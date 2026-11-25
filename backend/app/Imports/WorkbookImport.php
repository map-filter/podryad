<?php

namespace App\Imports;

use App\Models\DocumentType;
use App\Models\Organization;
use App\Models\Worker;
use App\Models\WorkerDocument;
use App\Support\Iin;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

class WorkbookImport
{
    public const WORKER_COLUMNS = ['Фамилия', 'Имя', 'Отчество', 'ИИН', 'Должность', 'Разряд', 'Участок', 'Телефон', 'Дата приёма'];

    public const DOC_COLUMNS = ['ИИН', 'Документ', 'Номер', 'Дата выдачи', 'Действует до', 'Кем выдан', 'Группа ЭБ'];

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<int, string> */
    private array $warnings = [];

    public function __construct(private readonly Organization $organization) {}

    public static function template(Organization $org): string
    {
        $dir = storage_path('app/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/template-'.bin2hex(random_bytes(4)).'.xlsx';
        $options = new Options;
        $writer = new Writer($options);
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();
        $writer->getCurrentSheet()->setName('Работники');
        $writer->addRow(Row::fromValues(self::WORKER_COLUMNS, $bold));
        $writer->addRow(Row::fromValues(['Сагындыков', 'Айдос', 'Маратович', '930412300271', 'Электромонтёр', 5, 'Электромонтажный участок', '+7 701 000 00 00', '01.03.2024']));
        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Документы');
        $writer->addRow(Row::fromValues(self::DOC_COLUMNS, $bold));
        $writer->addRow(Row::fromValues(['930412300271', 'ЭБ', '1452', '10.02.2026', '09.02.2027', 'Учебный центр, Павлодар', 'IV']));
        $writer->addRow(Row::fromValues(['930412300271', 'Медосмотр', 'МО-311', '15.01.2026', '', 'Поликлиника №1', '']));
        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Справочник');
        $writer->addRow(Row::fromValues(['Документ (можно писать полное или краткое название, или код)', 'Краткое', 'Код', 'Срок, мес.'], $bold));
        foreach (DocumentType::where('organization_id', $org->id)->orderBy('sort')->get() as $t) {
            $writer->addRow(Row::fromValues([$t->name, $t->short_name, $t->code, $t->validity_months ?? 'бессрочно']));
        }
        $writer->close();

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $path, bool $dryRun): array
    {
        $sheets = $this->read($path);
        $workerRows = $sheets['workers'];
        $docRows = $sheets['documents'];
        $types = DocumentType::where('organization_id', $this->organization->id)->get();
        $existing = Worker::where('organization_id', $this->organization->id)->get()->keyBy('iin');

        $workersPlan = [];
        foreach ($workerRows as $line => $row) {
            $iin = preg_replace('/\D/', '', (string) ($row['ИИН'] ?? ''));
            $last = trim((string) ($row['Фамилия'] ?? ''));
            $first = trim((string) ($row['Имя'] ?? ''));
            $position = trim((string) ($row['Должность'] ?? ''));
            if ($iin === '' && $last === '' && $first === '') {
                continue;
            }
            $where = 'Работники, строка '.$line;
            if (! Iin::isValid($iin)) {
                $this->errors[] = $where.': ИИН '.$iin.' не прошёл проверку';

                continue;
            }
            if ($last === '' || $first === '' || $position === '') {
                $this->errors[] = $where.': нужны фамилия, имя и должность';

                continue;
            }
            $grade = trim((string) ($row['Разряд'] ?? ''));
            $workersPlan[$iin] = [
                'last_name' => $last,
                'first_name' => $first,
                'middle_name' => trim((string) ($row['Отчество'] ?? '')) ?: null,
                'iin' => $iin,
                'birth_date' => Iin::birthDate($iin)?->toDateString(),
                'position' => $position,
                'grade' => ctype_digit($grade) && (int) $grade >= 1 && (int) $grade <= 8 ? (int) $grade : null,
                'site' => trim((string) ($row['Участок'] ?? '')) ?: null,
                'phone' => trim((string) ($row['Телефон'] ?? '')) ?: null,
                'hired_at' => $this->date($row['Дата приёма'] ?? null),
                'exists' => $existing->has($iin),
            ];
        }

        $docsPlan = [];
        foreach ($docRows as $line => $row) {
            $iin = preg_replace('/\D/', '', (string) ($row['ИИН'] ?? ''));
            $typeName = trim((string) ($row['Документ'] ?? ''));
            if ($iin === '' && $typeName === '') {
                continue;
            }
            $where = 'Документы, строка '.$line;
            if (! isset($workersPlan[$iin]) && ! $existing->has($iin)) {
                $this->errors[] = $where.': работник с ИИН '.$iin.' не найден ни в файле, ни в кабинете';

                continue;
            }
            $type = $this->matchType($types, $typeName);
            if (! $type) {
                $this->errors[] = $where.': неизвестный документ «'.$typeName.'»';

                continue;
            }
            $issued = $this->date($row['Дата выдачи'] ?? null);
            $until = $this->date($row['Действует до'] ?? null);
            if (! $until && $issued && $type->validity_months) {
                $until = CarbonImmutable::parse($issued)->addMonthsNoOverflow($type->validity_months)->subDay()->toDateString();
                $this->warnings[] = $where.': срок действия рассчитан по справочнику ('.$type->validity_months.' мес.)';
            }
            if (! $until && $type->validity_months) {
                $this->errors[] = $where.': нет срока действия и даты выдачи';

                continue;
            }
            $group = $this->group($row['Группа ЭБ'] ?? null);
            if ($type->has_group && ! $group) {
                $this->errors[] = $where.': для «'.$type->short_name.'» нужна группа I-V';

                continue;
            }
            $docsPlan[] = [
                'iin' => $iin,
                'document_type_id' => $type->id,
                'type' => $type->short_name,
                'number' => trim((string) ($row['Номер'] ?? '')) ?: null,
                'issued_at' => $issued,
                'valid_until' => $until,
                'issuer' => trim((string) ($row['Кем выдан'] ?? '')) ?: null,
                'group_level' => $type->has_group ? $group : null,
            ];
        }

        $result = [
            'dry_run' => $dryRun,
            'workers_new' => count(array_filter($workersPlan, fn ($w) => ! $w['exists'])),
            'workers_updated' => count(array_filter($workersPlan, fn ($w) => $w['exists'])),
            'documents' => count($docsPlan),
            'documents_skipped' => 0,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'preview' => array_slice(array_values(array_map(fn ($w) => [
                'iin' => $w['iin'], 'name' => trim($w['last_name'].' '.$w['first_name'].' '.($w['middle_name'] ?? '')), 'position' => $w['position'], 'exists' => $w['exists'],
            ], $workersPlan)), 0, 50),
        ];
        if ($dryRun) {
            return $result;
        }

        DB::transaction(function () use ($workersPlan, $docsPlan, &$result) {
            $ids = [];
            foreach ($workersPlan as $iin => $w) {
                unset($w['exists']);
                $worker = Worker::updateOrCreate(['organization_id' => $this->organization->id, 'iin' => $iin], $w + ['is_active' => true]);
                $ids[$iin] = $worker->id;
            }
            $skipped = 0;
            foreach ($docsPlan as $d) {
                $workerId = $ids[$d['iin']] ?? Worker::where('organization_id', $this->organization->id)->where('iin', $d['iin'])->value('id');
                $dup = WorkerDocument::where('worker_id', $workerId)->where('document_type_id', $d['document_type_id'])
                    ->where('number', $d['number'])->where('valid_until', $d['valid_until'])->exists();
                if ($dup) {
                    $skipped++;

                    continue;
                }
                WorkerDocument::create([
                    'worker_id' => $workerId,
                    'document_type_id' => $d['document_type_id'],
                    'number' => $d['number'],
                    'issued_at' => $d['issued_at'],
                    'valid_until' => $d['valid_until'],
                    'issuer' => $d['issuer'],
                    'group_level' => $d['group_level'],
                ]);
            }
            $result['documents'] -= $skipped;
            $result['documents_skipped'] = $skipped;
        });

        return $result;
    }

    /**
     * @return array{workers: array<int, array<string, mixed>>, documents: array<int, array<string, mixed>>}
     */
    private function read(string $path): array
    {
        $out = ['workers' => [], 'documents' => []];
        $reader = new Reader;
        try {
            $reader->open($path);
        } catch (Throwable) {
            throw new \RuntimeException('Не удалось прочитать файл: нужен Excel .xlsx');
        }
        foreach ($reader->getSheetIterator() as $sheet) {
            $name = mb_strtolower(trim($sheet->getName()));
            $kind = str_starts_with($name, 'работ') ? 'workers' : (str_starts_with($name, 'док') ? 'documents' : null);
            if (! $kind) {
                continue;
            }
            $header = null;
            $line = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $line++;
                $values = $row->toArray();
                if ($header === null) {
                    $header = array_map(fn ($v) => $this->headerKey((string) $v), $values);

                    continue;
                }
                $assoc = [];
                foreach ($header as $i => $key) {
                    if ($key !== '') {
                        $assoc[$key] = $values[$i] ?? null;
                    }
                }
                $out[$kind][$line] = $assoc;
            }
        }
        $reader->close();
        if (! $out['workers'] && ! $out['documents']) {
            throw new \RuntimeException('В файле нет листов «Работники» или «Документы» с данными. Скачайте шаблон');
        }

        return $out;
    }

    private function headerKey(string $v): string
    {
        $v = mb_strtolower(trim($v));
        $map = [
            'фамилия' => 'Фамилия', 'имя' => 'Имя', 'отчество' => 'Отчество', 'иин' => 'ИИН', 'должность' => 'Должность',
            'разряд' => 'Разряд', 'участок' => 'Участок', 'телефон' => 'Телефон', 'дата приёма' => 'Дата приёма', 'дата приема' => 'Дата приёма',
            'документ' => 'Документ', 'тип документа' => 'Документ', 'номер' => 'Номер', 'дата выдачи' => 'Дата выдачи',
            'действует до' => 'Действует до', 'срок действия' => 'Действует до', 'кем выдан' => 'Кем выдан', 'группа эб' => 'Группа ЭБ', 'группа' => 'Группа ЭБ',
        ];

        return $map[$v] ?? '';
    }

    private function matchType($types, string $name): ?DocumentType
    {
        $n = mb_strtolower(trim($name));
        if ($n === '') {
            return null;
        }
        foreach ($types as $t) {
            if (in_array($n, [mb_strtolower($t->name), mb_strtolower($t->short_name), mb_strtolower($t->code)], true)) {
                return $t;
            }
        }
        foreach ($types as $t) {
            if (mb_strlen($n) >= 4 && str_contains(mb_strtolower($t->name), $n)) {
                return $t;
            }
        }

        return null;
    }

    private function date(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return CarbonImmutable::instance($v)->toDateString();
        }
        if (is_int($v) || is_float($v)) {
            if ($v > 20000 && $v < 80000) {
                return CarbonImmutable::create(1899, 12, 30)->addDays((int) $v)->toDateString();
            }

            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y', 'd.m.y'] as $f) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$f, $s);
                if ($d && $d->format($f) === $s) {
                    return $d->toDateString();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function group(mixed $v): ?int
    {
        $s = mb_strtoupper(trim((string) $v));
        $map = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5];

        return $map[$s] ?? null;
    }
}
