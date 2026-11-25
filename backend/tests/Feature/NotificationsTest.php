<?php

namespace Tests\Feature;

use App\Mail\ExpiryDigest;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Worker;
use App\Models\WorkerDocument;
use App\Services\ReadinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_daily_command_sends_email_and_logs_telegram_without_token(): void
    {
        Mail::fake();
        $this->artisan('permits:notify')->assertSuccessful();
        $email = NotificationLog::where('channel', 'email')->get();
        $this->assertNotEmpty($email);
        $this->assertTrue($email->every(fn ($l) => $l->status === 'sent'));
        Mail::assertSent(ExpiryDigest::class, fn ($m) => $m->hasTo('ot@ertis.test'));
        $tg = NotificationLog::where('channel', 'telegram')->first();
        $this->assertSame('logged', $tg->status);
        $this->assertStringContainsString('Токен бота не задан', $tg->error);
        $this->assertStringContainsString('просрочено', $tg->body);
        $this->assertNotEmpty($tg->items);

        $count = NotificationLog::count();
        $this->artisan('permits:notify')->assertSuccessful();
        $this->assertSame($count, NotificationLog::count(), 'second run on the same day must not duplicate');
    }

    public function test_lower_threshold_triggers_new_notification_and_renewal_resets(): void
    {
        Mail::fake();
        $today = ReadinessReport::today();
        $worker = Worker::where('last_name', 'Ахметов')->first();
        $doc = WorkerDocument::where('worker_id', $worker->id)->whereHas('type', fn ($q) => $q->where('code', 'ot'))->first();
        $doc->update(['valid_until' => $today->addDays(20)->toDateString(), 'notified_threshold' => null]);

        $this->artisan('permits:notify')->assertSuccessful();
        $this->assertSame(30, $doc->fresh()->notified_threshold);
        $this->artisan('permits:notify', ['--date' => $today->addDays(8)->toDateString()])->assertSuccessful();
        $this->assertSame(14, $doc->fresh()->notified_threshold);
        $this->artisan('permits:notify', ['--date' => $today->addDays(14)->toDateString()])->assertSuccessful();
        $this->assertSame(7, $doc->fresh()->notified_threshold);
        $last = NotificationLog::where('channel', 'telegram')->latest('id')->first();
        $this->assertContains($doc->id, array_column($last->items, 'document_id'));

        $this->asHead();
        $this->post('/api/documents/'.$doc->id, ['document_type_id' => $doc->document_type_id, 'valid_until' => $today->addYear()->toDateString()], ['Accept' => 'application/json'])->assertOk();
        $this->assertNull($doc->fresh()->notified_threshold);
    }

    public function test_telegram_is_sent_when_token_and_chat_are_set(): void
    {
        Mail::fake();
        config(['permits.telegram.token' => 'TEST:TOKEN']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Organization::first()->update(['telegram_chat_id' => '-100123']);
        $this->artisan('permits:notify')->assertSuccessful();
        $tg = NotificationLog::where('channel', 'telegram')->first();
        $this->assertSame('sent', $tg->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/botTEST:TOKEN/sendMessage') && $req['chat_id'] === '-100123');
    }

    public function test_journal_api_and_manual_run(): void
    {
        Mail::fake();
        $this->asHead();
        $run = $this->postJson('/api/notifications/run')->assertOk()->json();
        $this->assertGreaterThan(0, $run['created']);
        $list = $this->getJson('/api/notifications')->assertOk()->json();
        $this->assertSame($run['created'], $list['meta']['total']);
        $this->getJson('/api/notifications?channel=telegram')->assertOk()->assertJsonPath('data.0.channel', 'telegram');
    }
}
