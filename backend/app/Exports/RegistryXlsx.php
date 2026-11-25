<?php

namespace App\Exports;

use App\Models\Organization;
use App\Models\Worker;
use App\Services\ReadinessReport;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class RegistryXlsx
{
    public const STATE_LABELS = [
        'valid' => 'действует',
        'unlimited' => 'бессрочно',
        'expiring' => 'истекает',
        'expired' => 'просрочен',
    ];

    public function __construct(private readonly Organization $organization) {}

    public function build(): string
    {
        $report = new ReadinessReport($this->organization);
        $dir = storage_path('app/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/registry-'.$this->organization->id.'-'.bin2hex(random_bytes(4)).'.xlsx';
        $options = new Options;
        foreach ([1 => 34, 2 => 15, 3 => 28, 4 => 20, 5 => 40, 6 => 7, 7 => 16, 8 => 13, 9 => 13, 10 => 10, 11 => 13, 12 => 30] as $col => $w) {
            $options->setColumnWidth($w, $col);
        }
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Реестр сроков');
        $bold = (new Style)->setFontBold();
        $red = (new Style)->setFontColor(Color::rgb(178, 48, 43));
        $amber = (new Style)->setFontColor(Color::rgb(138, 90, 0));
        $writer->addRow(Row::fromValues(['Реестр сроков документов: '.$this->organization->name.', БИН '.$this->organization->bin.', на '.now()->format('d.m.Y')], (new Style)->setFontBold()->setFontSize(13)));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['ФИО', 'ИИН', 'Должность', 'Участок', 'Документ', 'Группа', 'Номер', 'Выдан', 'Действует до', 'Осталось дней', 'Статус', 'Кем выдан'], $bold));
        $workers = Worker::where('organization_id', $this->organization->id)->where('is_active', true)
            ->with('documents.type')->orderBy('last_name')->orderBy('first_name')->get();
        foreach ($workers as $w) {
            $docs = $w->documents->sortBy(fn ($d) => [$d->type->sort, $d->valid_until?->getTimestamp() ?? PHP_INT_MAX]);
            foreach ($docs as $d) {
                $st = $report->documentState($d);
                $style = $st['state'] === 'expired' ? $red : ($st['state'] === 'expiring' ? $amber : null);
                $writer->addRow(Row::fromValues([
                    $w->fullName(),
                    $w->iin,
                    $w->position,
                    (string) $w->site,
                    $d->type->name,
                    $d->group_level ? \App\Services\Readiness::roman($d->group_level) : '',
                    (string) $d->number,
                    $d->issued_at?->format('d.m.Y') ?? '',
                    $d->valid_until?->format('d.m.Y') ?? 'бессрочно',
                    $st['days_left'] ?? '',
                    self::STATE_LABELS[$st['state']] ?? $st['state'],
                    (string) $d->issuer,
                ], $style));
            }
        }
        $writer->close();

        return $path;
    }
}
