<?php

namespace Tests\Feature;

use App\Models\AdmissionRequest;
use App\Models\Customer;
use App\Models\RequirementProfile;
use App\Models\Worker;
use App\Services\ReadinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

class RequestFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function scratch(string $name, string $content): string
    {
        $dir = sys_get_temp_dir().'/podryad-tests';
        @mkdir($dir, 0777, true);
        $path = $dir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    /** @return array<int, array<int, mixed>> */
    private function readXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    private function newRequest(array $workerIds): array
    {
        $profile = RequirementProfile::where('name', 'ГРЭС: работы в электроустановках')->first();
        $customer = Customer::where('short_name', 'ГРЭС-1')->first();
        $today = ReadinessReport::today();

        return $this->postJson('/api/requests', [
            'customer_id' => $customer->id,
            'facility_id' => $customer->facilities()->first()->id,
            'requirement_profile_id' => $profile->id,
            'start_date' => $today->addDays(2)->toDateString(),
            'end_date' => $today->addDays(12)->toDateString(),
            'worker_ids' => $workerIds,
        ])->assertCreated()->json();
    }

    public function test_full_flow_sign_submit_customer_approves_and_package(): void
    {
        $this->asHead();
        $ready = Worker::whereIn('last_name', ['Ахметов', 'Петренко'])->pluck('id')->all();
        $notReady = Worker::where('last_name', 'Искаков')->value('id');
        $r = $this->newRequest(array_merge($ready, [$notReady]));
        $this->assertSame('draft', $r['status']);
        $this->assertMatchesRegularExpression('/^ДП-\d{4}-\d{3}$/', $r['number']);
        $this->assertSame('not_ready', $r['readiness']['status']);
        $this->assertSame(1, $r['readiness']['counts']['not_ready']);
        $this->getJson('/api/requests/'.$r['id'].'/sign-data')->assertStatus(409);

        $this->postJson('/api/requests/'.$r['id'].'/workers', ['remove' => [$notReady]])->assertOk()->assertJsonPath('workers_count', 2);
        $sd = $this->getJson('/api/requests/'.$r['id'].'/sign-data')->assertOk()->json();
        $this->assertSame('emulate', $sd['sign_mode']);
        $this->assertStringContainsString('"number": "'.$r['number'].'"', $sd['data']);
        $this->postJson('/api/requests/'.$r['id'].'/submit')->assertStatus(409);

        $sig = $this->postJson('/api/sign/emulate', ['data' => $sd['data_base64']])->assertOk()->json('signature');
        $this->postJson('/api/requests/'.$r['id'].'/sign', ['signature' => base64_encode('garbage')])->assertStatus(422);
        $signed = $this->postJson('/api/requests/'.$r['id'].'/sign', ['signature' => $sig])->assertOk()->json();
        $this->assertTrue($signed['signature_valid']);
        $this->assertStringContainsString('эмуляция', $signed['signer_subject']);

        $extra = Worker::where('last_name', 'Ковалёв')->value('id');
        $changed = $this->postJson('/api/requests/'.$r['id'].'/workers', ['add' => [$extra]])->assertOk()->json();
        $this->assertFalse($changed['signature_valid']);
        $this->postJson('/api/requests/'.$r['id'].'/submit')->assertStatus(409);
        $this->postJson('/api/requests/'.$r['id'].'/workers', ['remove' => [$extra]])->assertOk()->assertJsonPath('signature_valid', true);

        $this->postJson('/api/requests/'.$r['id'].'/submit')->assertOk()->assertJsonPath('status', 'submitted');
        $this->putJson('/api/requests/'.$r['id'], [])->assertStatus(409);

        $this->actingAsEmail('ot.pnhz@zakazchik.test');
        $this->getJson('/api/customer/requests/'.$r['id'])->assertStatus(404);

        $this->actingAsEmail('ot.gres1@zakazchik.test');
        $list = $this->getJson('/api/customer/requests')->assertOk()->json('data');
        $this->assertContains($r['id'], array_column($list, 'id'));
        $view = $this->getJson('/api/customer/requests/'.$r['id'])->assertOk()->json();
        $this->assertCount(2, $view['workers']);
        $docId = collect($view['workers'][0]['readiness']['items'])->firstWhere('has_scan', true)['document_id'];
        $this->get('/api/documents/'.$docId.'/scan')->assertOk();

        $this->postJson('/api/customer/requests/'.$r['id'].'/decision', ['decision' => 'remarks'])->assertStatus(422);
        $this->postJson('/api/customer/requests/'.$r['id'].'/decision', [
            'decision' => 'remarks',
            'comment' => 'Приложите удостоверение ЭБ нового образца',
            'workers' => [['id' => $ready[0], 'decision' => 'remark', 'comment' => 'нечитаемый скан']],
        ])->assertOk()->assertJsonPath('status', 'remarks');

        $this->asHead();
        $back = $this->getJson('/api/requests/'.$r['id'])->assertOk()->json();
        $this->assertSame('Приложите удостоверение ЭБ нового образца', $back['review_comment']);
        $this->assertSame('remark', collect($back['workers'])->firstWhere('id', $ready[0])['customer_decision']);
        $this->postJson('/api/requests/'.$r['id'].'/submit')->assertOk()->assertJsonPath('status', 'submitted');

        $this->actingAsEmail('ot.gres1@zakazchik.test');
        $this->postJson('/api/customer/requests/'.$r['id'].'/decision', ['decision' => 'approved', 'comment' => 'Допущены'])
            ->assertOk()->assertJsonPath('status', 'approved');
        $zipRes = $this->get('/api/customer/requests/'.$r['id'].'/package')->assertOk();
        $this->assertNotEmpty($zipRes->baseResponse->getFile()->getSize());
    }

    public function test_package_zip_has_pdf_xlsx_signature_and_scans(): void
    {
        $this->asHead();
        $r = AdmissionRequest::where('status', 'submitted')->first();
        $res = $this->get('/api/requests/'.$r->id.'/package')->assertOk()->assertHeader('Content-Type', 'application/zip');
        $path = $res->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertContains('00_Список_на_допуск_'.$r->number.'.pdf', $names);
        $this->assertContains('00_Список_на_пропуск_'.$r->number.'.xlsx', $names);
        $this->assertContains('00_Подпись/'.$r->number.'.cms', $names);
        $this->assertContains('00_Состав_пакета.txt', $names);
        $workerDirs = array_unique(array_filter(array_map(fn ($n) => str_contains($n, '/') && preg_match('/^\d{2}_/', $n) && ! str_starts_with($n, '00_') ? explode('/', $n)[0] : null, $names)));
        $this->assertCount($r->workers()->count(), $workerDirs);
        $scans = array_filter($names, fn ($n) => preg_match('/^0[1-9]_.+\/.+\.jpg$/u', $n));
        $this->assertNotEmpty($scans);
        $pdf = $zip->getFromName('00_Список_на_допуск_'.$r->number.'.pdf');
        $this->assertStringStartsWith('%PDF', $pdf);
        $payload = json_decode($zip->getFromName('00_Подпись/подписанные_данные.json'), true);
        $this->assertSame($r->number, $payload['number']);
        $this->assertCount($r->workers()->count(), $payload['workers']);
        $zip->close();

        $pdfPath = $this->scratch('list.pdf', $pdf);
        $this->assertGreaterThan(5000, filesize($pdfPath));
    }

    public function test_pass_list_and_registry_xlsx_are_readable(): void
    {
        $this->asHead();
        $r = AdmissionRequest::where('status', 'draft')->first();
        $res = $this->get('/api/requests/'.$r->id.'/pass-list')->assertOk();
        $rows = $this->readXlsx($res->baseResponse->getFile()->getPathname());
        $this->assertSame('Список работников на оформление пропуска', $rows[0][0]);
        $hi = array_search('№', array_column($rows, 0), true);
        $this->assertNotFalse($hi);
        $this->assertSame(['№', 'Фамилия', 'Имя', 'Отчество', 'ИИН'], array_slice($rows[$hi], 0, 5));
        $data = array_slice($rows, $hi + 1);
        $this->assertCount($r->workers()->count(), $data);
        foreach ($data as $row) {
            $this->assertTrue(\App\Support\Iin::isValid((string) $row[4]));
        }

        $res = $this->get('/api/exports/registry')->assertOk();
        $rows = $this->readXlsx($res->baseResponse->getFile()->getPathname());
        $hi = array_search('ФИО', array_column($rows, 0), true);
        $this->assertNotFalse($hi);
        $statuses = array_unique(array_column(array_slice($rows, $hi + 1), 10));
        $this->assertContains('просрочен', $statuses);
        $this->assertContains('истекает', $statuses);
        $this->assertContains('действует', $statuses);

        $pdf = $this->get('/api/requests/'.$r->id.'/summary.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_emulated_signature_for_other_data_is_rejected(): void
    {
        $this->asHead();
        $r = $this->newRequest(Worker::whereIn('last_name', ['Ахметов'])->pluck('id')->all());
        $sig = $this->postJson('/api/sign/emulate', ['data' => base64_encode('другой список')])->json('signature');
        $this->postJson('/api/requests/'.$r['id'].'/sign', ['signature' => $sig])
            ->assertStatus(422)->assertJsonPath('errors.signature.0', 'Подписан другой список: обновите страницу и подпишите заново');
    }

    public function test_foreman_cannot_sign(): void
    {
        $this->asHead();
        $r = $this->newRequest(Worker::whereIn('last_name', ['Ахметов'])->pluck('id')->all());
        $this->actingAsEmail('master@ertis.test');
        $this->getJson('/api/requests/'.$r['id'].'/sign-data')->assertStatus(403);
    }
}
