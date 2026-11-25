<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Services\ReadinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WorkersAndDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    public function test_iin_checksum_is_validated(): void
    {
        $this->asHead();
        $this->postJson('/api/workers', ['last_name' => 'Иванов', 'first_name' => 'Иван', 'iin' => '880314300155', 'position' => 'Слесарь'])
            ->assertStatus(422)
            ->assertJsonPath('errors.iin.0', 'ИИН не прошёл проверку контрольной суммы, проверьте цифры');
        $this->postJson('/api/workers', ['last_name' => 'Иванов', 'first_name' => 'Иван', 'iin' => '88031430015', 'position' => 'Слесарь'])
            ->assertStatus(422);
    }

    public function test_create_worker_add_documents_and_see_traffic_light(): void
    {
        $this->asHead();
        $w = $this->postJson('/api/workers', [
            'last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => 'Иванович',
            'iin' => '880314300158', 'position' => 'Электромонтажник', 'grade' => 4, 'site' => 'Электромонтажный участок',
        ])->assertCreated()->assertJsonPath('birth_date', '1988-03-14')->json();
        $this->assertSame('not_ready', $w['readiness']['status']);
        $this->assertCount(4, $w['readiness']['problems']);

        $today = ReadinessReport::today();
        $this->postJson('/api/workers', ['last_name' => 'Дубль', 'first_name' => 'Д', 'iin' => '880314300158', 'position' => 'X'])
            ->assertStatus(422)->assertJsonPath('errors.iin.0', 'Работник с таким ИИН уже есть');

        $scan = UploadedFile::fake()->image('med.jpg', 800, 600);
        $doc = $this->post('/api/workers/'.$w['id'].'/documents', [
            'document_type_id' => $this->type('med')->id,
            'number' => 'МО-1',
            'issued_at' => $today->subMonths(2)->toDateString(),
            'scan' => $scan,
        ], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertTrue($doc['has_scan']);
        $this->assertSame($today->subMonths(2)->addMonthsNoOverflow(12)->subDay()->toDateString(), $doc['valid_until']);
        $this->get('/api/documents/'.$doc['id'].'/scan')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->postJson('/api/workers/'.$w['id'].'/documents', [
            'document_type_id' => $this->type('eb')->id, 'number' => 'ЭБ-1', 'valid_until' => $today->addYear()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('errors.group_level.0', 'Укажите группу по электробезопасности (I-V)');

        $this->postJson('/api/workers/'.$w['id'].'/documents', ['document_type_id' => $this->type('intro')->id, 'issued_at' => $today->subYear()->toDateString()])->assertCreated();
        $this->postJson('/api/workers/'.$w['id'].'/documents', ['document_type_id' => $this->type('ot')->id, 'valid_until' => $today->addDays(10)->toDateString()])->assertCreated();
        $ppe = $this->postJson('/api/workers/'.$w['id'].'/documents', ['document_type_id' => $this->type('ppe')->id, 'valid_until' => $today->subDay()->toDateString()])->assertCreated()->json();
        $this->assertSame('expired', $ppe['state']);

        $card = $this->getJson('/api/workers/'.$w['id'])->assertOk()->json();
        $this->assertSame('not_ready', $card['readiness']['status']);
        $this->assertStringContainsString('просрочено', implode(' ', $card['readiness']['problems']));

        $this->post('/api/documents/'.$ppe['id'], ['document_type_id' => $this->type('ppe')->id, 'valid_until' => $today->addMonths(6)->toDateString()], ['Accept' => 'application/json'])->assertOk();
        $card = $this->getJson('/api/workers/'.$w['id'])->json();
        $this->assertSame('expiring', $card['readiness']['status']);
        $this->assertSame(14, $card['readiness']['threshold']);
        $this->assertSame(10, $card['readiness']['days_left']);

        $list = $this->getJson('/api/workers?status=expiring&search=иванов')->assertOk()->json('data');
        $this->assertSame([$w['id']], array_column($list, 'id'));
    }

    public function test_validity_before_issue_is_rejected(): void
    {
        $this->asHead();
        $worker = \App\Models\Worker::first();
        $this->postJson('/api/workers/'.$worker->id.'/documents', [
            'document_type_id' => $this->type('ot')->id, 'issued_at' => '2026-05-01', 'valid_until' => '2026-04-01',
        ])->assertStatus(422)->assertJsonPath('errors.valid_until.0', 'Срок действия не может быть раньше даты выдачи');
    }

    public function test_dashboard_shows_tomorrow_and_expiring(): void
    {
        $this->asHead();
        $d = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame(ReadinessReport::today()->addDay()->toDateString(), $d['date']);
        $this->assertSame($d['totals']['workers'], $d['totals']['ready'] + $d['totals']['expiring'] + $d['totals']['not_ready']);
        $this->assertNotEmpty($d['expiring']);
        $this->assertNotEmpty($d['expired']);
        $this->assertNotEmpty($d['profiles']);
        $names = array_map(fn ($r) => $r['worker']['full_name'].'|'.$r['type_short'], $d['expired']);
        $this->assertNotContains('Белоусов Евгений Павлович|ПБ', $names, 'renewed document must not be listed as expired');
        $profile = $d['profiles'][0]['id'];
        $p = $this->getJson('/api/dashboard?profile_id='.$profile)->assertOk()->json();
        $this->assertSame($profile, $p['profile_id']);
    }

    public function test_profile_low_group_detected(): void
    {
        $this->asHead();
        $profile = \App\Models\RequirementProfile::where('name', 'ГРЭС: работы в электроустановках')->first();
        $rows = $this->getJson('/api/profiles/'.$profile->id.'/readiness')->assertOk()->json('data');
        $iskakov = collect($rows)->firstWhere('last_name', 'Искаков');
        $this->assertSame('not_ready', $iskakov['readiness']['status']);
        $this->assertContains('low_group', array_column($iskakov['readiness']['items'], 'state'));
    }
}
