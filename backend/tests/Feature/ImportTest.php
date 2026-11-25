<?php

namespace Tests\Feature;

use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function workbook(array $workers, array $docs): string
    {
        $path = sys_get_temp_dir().'/import-'.bin2hex(random_bytes(3)).'.xlsx';
        $w = new Writer;
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Работники');
        $w->addRow(Row::fromValues(['Фамилия', 'Имя', 'Отчество', 'ИИН', 'Должность', 'Разряд', 'Участок']));
        foreach ($workers as $r) {
            $w->addRow(Row::fromValues($r));
        }
        $w->addNewSheetAndMakeItCurrent();
        $w->getCurrentSheet()->setName('Документы');
        $w->addRow(Row::fromValues(['ИИН', 'Документ', 'Номер', 'Дата выдачи', 'Действует до', 'Кем выдан', 'Группа ЭБ']));
        foreach ($docs as $r) {
            $w->addRow(Row::fromValues($r));
        }
        $w->close();

        return $path;
    }

    public function test_template_downloads(): void
    {
        $this->asHead();
        $this->get('/api/import/template')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_dry_run_reports_errors_then_import_creates_workers_and_documents(): void
    {
        $this->asHead();
        $path = $this->workbook([
            ['Сарсенов', 'Нурбол', 'Асетович', '880314300158', 'Электромонтажник', '4', 'Электромонтажный участок'],
            ['Плохой', 'ИИН', '', '880314300155', 'Слесарь', '', ''],
        ], [
            ['880314300158', 'ЭБ', '77', '01.02.2026', '31.01.2027', 'УЦ', 'IV'],
            ['880314300158', 'Медосмотр', 'МО-9', new \DateTimeImmutable('2026-03-01'), '', 'Поликлиника', ''],
            ['880314300158', 'Неизвестный документ', '1', '', '01.01.2027', '', ''],
        ]);
        $file = new UploadedFile($path, 'workers.xlsx', null, null, true);
        $dry = $this->post('/api/import', ['file' => $file, 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(1, $dry['workers_new']);
        $this->assertSame(2, $dry['documents']);
        $this->assertCount(2, $dry['errors']);
        $this->assertNull(Worker::where('iin', '880314300158')->first());

        $file = new UploadedFile($path, 'workers.xlsx', null, null, true);
        $res = $this->post('/api/import', ['file' => $file, 'dry_run' => '0'], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertFalse($res['dry_run']);
        $w = Worker::where('iin', '880314300158')->with('documents.type')->first();
        $this->assertNotNull($w);
        $this->assertSame('1988-03-14', $w->birth_date->toDateString());
        $this->assertCount(2, $w->documents);
        $eb = $w->documents->firstWhere('type.code', 'eb');
        $this->assertSame(4, $eb->group_level);
        $med = $w->documents->firstWhere('type.code', 'med');
        $this->assertSame('2027-02-28', $med->valid_until->toDateString());

        $file = new UploadedFile($path, 'workers.xlsx', null, null, true);
        $again = $this->post('/api/import', ['file' => $file, 'dry_run' => '0'], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(1, $again['workers_updated']);
        $this->assertSame(2, $again['documents_skipped']);
    }

    public function test_non_xlsx_is_rejected(): void
    {
        $this->asHead();
        $file = UploadedFile::fake()->create('list.csv', 1, 'text/csv');
        $this->post('/api/import', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
