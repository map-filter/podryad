<?php

namespace App\Exports;

use App\Models\AdmissionRequest;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class PassListXlsx
{
    public function __construct(private readonly AdmissionRequest $request)
    {
        $request->loadMissing(['organization', 'customer', 'facility', 'workers']);
    }

    public function build(): string
    {
        $r = $this->request;
        $dir = storage_path('app/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/pass-'.$r->id.'-'.bin2hex(random_bytes(4)).'.xlsx';
        $options = new Options;
        foreach ([1 => 5, 2 => 18, 3 => 16, 4 => 18, 5 => 15, 6 => 14, 7 => 30, 8 => 34, 9 => 14, 10 => 36, 11 => 13, 12 => 13] as $col => $w) {
            $options->setColumnWidth($w, $col);
        }
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Список на пропуск');
        $bold = (new Style)->setFontBold();
        $title = (new Style)->setFontBold()->setFontSize(13);
        $writer->addRow(Row::fromValues(['Список работников на оформление пропуска'], $title));
        $writer->addRow(Row::fromValues(['Заявка '.$r->number.', заказчик: '.$r->customer->name.($r->facility ? ', объект: '.$r->facility->name : '')]));
        $writer->addRow(Row::fromValues(['Подрядчик: '.$r->organization->name.', БИН '.$r->organization->bin.'. Период: '.$r->start_date->format('d.m.Y').' - '.$r->end_date->format('d.m.Y')]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['№', 'Фамилия', 'Имя', 'Отчество', 'ИИН', 'Дата рождения', 'Должность', 'Организация', 'БИН', 'Объект', 'Дата начала', 'Дата окончания'], $bold));
        $n = 0;
        foreach ($r->workers as $w) {
            $n++;
            $writer->addRow(Row::fromValues([
                $n,
                $w->last_name,
                $w->first_name,
                (string) $w->middle_name,
                $w->iin,
                $w->birth_date?->format('d.m.Y') ?? '',
                $w->position.($w->grade ? ', '.$w->grade.' разряд' : ''),
                $r->organization->name,
                $r->organization->bin,
                $r->facility?->name ?? $r->customer->short_name,
                $r->start_date->format('d.m.Y'),
                $r->end_date->format('d.m.Y'),
            ]));
        }
        $writer->close();

        return $path;
    }
}
