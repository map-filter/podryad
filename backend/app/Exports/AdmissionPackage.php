<?php

namespace App\Exports;

use App\Models\AdmissionRequest;
use App\Services\ListSigner;
use App\Services\Readiness;
use App\Services\ReadinessReport;
use App\Support\Scans;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use TCPDF;
use ZipArchive;

class AdmissionPackage
{
    /** @var array<string, mixed>|null */
    private ?array $rep = null;

    public function __construct(private readonly AdmissionRequest $request)
    {
        $request->loadMissing(['organization', 'customer', 'facility', 'profile.items.type', 'workers.documents.type', 'signer']);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return $this->rep ??= (new ReadinessReport($this->request->organization))->forRequest($this->request);
    }

    public static function safeName(string $value): string
    {
        $v = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F№]+/u', '', $value);
        $v = preg_replace('/\s+/u', '_', trim((string) $v));

        return mb_substr($v ?: 'file', 0, 80);
    }

    public static function disposition(string $filename, string $type = 'attachment'): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', \Illuminate\Support\Str::ascii($filename));

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $ascii, rawurlencode($filename));
    }

    private static function d(?string $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('d.m.Y') : 'бессрочно';
    }

    private static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    public function pdf(): string
    {
        $r = $this->request;
        $rep = $this->report();
        $org = $r->organization;
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Кабинет допуска подрядчика');
        $pdf->SetAuthor($org->name);
        $pdf->SetTitle('Список на допуск '.$r->number);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterFont(['dejavusans', '', 7]);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->AddPage();

        $statusLabel = [Readiness::READY => 'готов', Readiness::EXPIRING => 'готов, срок истекает', Readiness::NOT_READY => 'не готов'];
        $head = '<table cellpadding="1"><tr><td width="60%"><b style="font-size:13px">'.self::e($org->name).'</b><br>БИН '.self::e($org->bin)
            .($org->address ? '<br>'.self::e($org->address) : '').'</td><td width="40%" align="right">Заявка <b>'.self::e($r->number).'</b><br>Сформировано '
            .now()->format('d.m.Y H:i').'</td></tr></table>';
        $pdf->writeHTML($head, true, false, true, false, '');
        $pdf->Ln(2);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 7, 'Список работников на допуск к работам', 0, 1, 'C');
        $pdf->SetFont('dejavusans', '', 9);
        $meta = '<table cellpadding="2" border="0">'
            .'<tr><td width="18%" color="#555">Заказчик</td><td width="82%">'.self::e($r->customer->name).'</td></tr>'
            .'<tr><td color="#555">Объект</td><td>'.self::e($r->facility?->name ?? 'не указан').'</td></tr>'
            .'<tr><td color="#555">Вид работ</td><td>'.self::e($r->work_type).($r->work_description ? ': '.self::e($r->work_description) : '').'</td></tr>'
            .'<tr><td color="#555">Период работ</td><td>с '.self::d($r->start_date?->toDateString()).' по '.self::d($r->end_date?->toDateString()).'</td></tr>'
            .'<tr><td color="#555">Профиль требований</td><td>'.self::e($r->profile->name).'</td></tr>'
            .'<tr><td color="#555">Проверка сроков</td><td>на '.self::d($rep['on']).' с учётом окончания работ</td></tr>'
            .'</table>';
        $pdf->writeHTML($meta, true, false, true, false, '');

        $rows = '';
        $n = 0;
        foreach ($rep['workers'] as $row) {
            $w = $row['worker'];
            $res = $row['result'];
            $docs = [];
            foreach ($res['items'] as $it) {
                $line = self::e($it['short_name'] ?: $it['name']);
                if ($it['group_level']) {
                    $line .= ' гр. '.Readiness::roman((int) $it['group_level']);
                }
                if ($it['state'] === 'missing') {
                    $line .= ': <font color="#b2302b">нет</font>';
                } else {
                    $line .= ($it['number'] ? ' № '.self::e($it['number']) : '').($it['valid_until'] ? ', до '.self::d($it['valid_until']) : ', бессрочно');
                    if (in_array($it['state'], ['expired', 'low_group'], true)) {
                        $line = '<font color="#b2302b">'.$line.'</font>';
                    } elseif (in_array($it['state'], ['expiring', 'expires_during'], true)) {
                        $line = '<font color="#8a5a00">'.$line.'</font>';
                    }
                }
                $docs[] = $line;
            }
            $n++;
            $color = $res['status'] === Readiness::NOT_READY ? '#b2302b' : ($res['status'] === Readiness::EXPIRING ? '#8a5a00' : '#23733f');
            $rows .= '<tr nobr="true"><td width="4%" align="center">'.$n.'</td><td width="19%"><b>'.self::e($w->fullName()).'</b></td>'
                .'<td width="11%">'.self::e($w->iin).'</td><td width="15%">'.self::e($w->position).($w->grade ? ', '.$w->grade.' разряд' : '').'</td>'
                .'<td width="40%">'.implode('<br>', $docs).'</td><td width="11%" color="'.$color.'">'.$statusLabel[$res['status']].'</td></tr>';
        }
        $table = '<table border="0.3" cellpadding="3"><thead><tr style="background-color:#e8edf3;font-weight:bold">'
            .'<th width="4%" align="center">№</th><th width="19%">ФИО</th><th width="11%">ИИН</th><th width="15%">Должность</th>'
            .'<th width="40%">Документы и сроки</th><th width="11%">Готовность</th></tr></thead><tbody>'.$rows.'</tbody></table>';
        $pdf->writeHTML($table, true, false, true, false, '');
        $pdf->Ln(3);
        $c = $rep['counts'];
        $pdf->writeHTML('<p>Всего работников: <b>'.count($rep['workers']).'</b>; готовы: '.$c[Readiness::READY].', срок истекает: '.$c[Readiness::EXPIRING].', не готовы: '.$c[Readiness::NOT_READY].'.</p>', true, false, true, false, '');
        $pdf->Ln(2);
        if ($r->signed_at) {
            $hash = (new ListSigner)->hash($r);
            $valid = $hash === $r->signed_hash;
            $sign = '<table border="0.3" cellpadding="4"><tr><td><b>Список подписан ЭЦП</b>'.($r->sign_mode === 'emulate' ? ' (режим эмуляции, без настоящего ключа)' : '')
                .'<br>Подписант: '.self::e($r->signer_subject).'<br>Дата подписи: '.$r->signed_at->timezone(config('app.timezone'))->format('d.m.Y H:i')
                .'<br>Хеш SHA-256 подписанных данных: <font face="dejavusansmono" size="7">'.self::e($r->signed_hash).'</font>'
                .($valid ? '' : '<br><font color="#b2302b">Внимание: после подписи список менялся, подпись не соответствует текущим данным</font>')
                .'</td></tr></table>';
            $pdf->writeHTML($sign, true, false, true, false, '');
        } else {
            $pdf->writeHTML('<p color="#8a5a00">Список не подписан ЭЦП.</p><br><p>Ответственный: ____________________ / ____________________ /</p>', true, false, true, false, '');
        }

        return $pdf->Output('list.pdf', 'S');
    }

    public function zip(): string
    {
        $r = $this->request;
        $rep = $this->report();
        $dir = storage_path('app/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/package-'.$r->id.'-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать архив');
        }
        $number = self::safeName($r->number);
        $zip->addFromString('00_Список_на_допуск_'.$number.'.pdf', $this->pdf());
        $zip->addFile((new PassListXlsx($r))->build(), '00_Список_на_пропуск_'.$number.'.xlsx');
        if ($r->signed_at && $r->signature) {
            $zip->addFromString('00_Подпись/'.$number.'.cms', $r->signature);
            $zip->addFromString('00_Подпись/подписанные_данные.json', (new ListSigner)->payloadText($r));
        }
        $readme = ["Пакет допуска {$r->number}", 'Заказчик: '.$r->customer->name, 'Объект: '.($r->facility?->name ?? 'не указан'),
            'Вид работ: '.$r->work_type, 'Работников: '.count($rep['workers']), '', 'Папки работников содержат сканы документов из профиля требований.'];
        $missing = [];
        $n = 0;
        foreach ($rep['workers'] as $row) {
            $n++;
            $w = $row['worker'];
            $folder = sprintf('%02d_%s_%s', $n, self::safeName($w->last_name.'_'.$w->first_name), $w->iin);
            $zip->addEmptyDir($folder);
            $docs = $w->documents->keyBy('id');
            $used = [];
            foreach ($row['result']['items'] as $it) {
                if (! $it['document_id']) {
                    $missing[] = $w->fullName().': '.$it['name'].' (нет документа)';

                    continue;
                }
                $doc = $docs[$it['document_id']] ?? null;
                if (! $doc || ! $doc->scan_path || ! Storage::disk('local')->exists($doc->scan_path)) {
                    $missing[] = $w->fullName().': '.$it['name'].' (нет скана)';

                    continue;
                }
                $ext = Scans::extension($doc->scan_path, $doc->scan_mime);
                $base = self::safeName(($it['short_name'] ?: $it['code']).($doc->number ? '_'.$doc->number : '').'_до_'.($doc->valid_until ? $doc->valid_until->format('d.m.Y') : 'бессрочно'));
                $name = $base;
                $i = 2;
                while (isset($used[$name])) {
                    $name = $base.'_'.$i++;
                }
                $used[$name] = true;
                $zip->addFile(Storage::disk('local')->path($doc->scan_path), $folder.'/'.$name.'.'.$ext);
            }
        }
        if ($missing) {
            $readme[] = '';
            $readme[] = 'Не приложены:';
            foreach ($missing as $m) {
                $readme[] = '- '.$m;
            }
        }
        $zip->addFromString('00_Состав_пакета.txt', implode("\r\n", $readme)."\r\n");
        $zip->close();

        return $path;
    }
}
