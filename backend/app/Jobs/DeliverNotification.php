<?php

namespace App\Jobs;

use App\Mail\ExpiryDigest;
use App\Models\NotificationLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $logId) {}

    public function handle(): void
    {
        $log = NotificationLog::find($this->logId);
        if (! $log || $log->status !== 'queued') {
            return;
        }
        try {
            match ($log->channel) {
                'email' => $this->email($log),
                'telegram' => $this->telegram($log),
                default => $log->update(['status' => 'logged', 'sent_at' => now()]),
            };
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    private function email(NotificationLog $log): void
    {
        Mail::to($log->recipient)->send(new ExpiryDigest($log));
        $log->update(['status' => 'sent', 'sent_at' => now()]);
    }

    private function telegram(NotificationLog $log): void
    {
        $token = config('permits.telegram.token');
        if (! $token || ! $log->recipient) {
            Log::info('telegram notification (no bot token or chat id)', ['log_id' => $log->id, 'subject' => $log->subject, 'body' => $log->body]);
            $log->update([
                'status' => 'logged',
                'sent_at' => now(),
                'error' => ! $token ? 'Токен бота не задан в .env, сообщение записано в журнал' : 'Не указан чат Telegram в настройках, сообщение записано в журнал',
            ]);

            return;
        }
        $response = Http::timeout(15)->asJson()->post(rtrim(config('permits.telegram.api'), '/').'/bot'.$token.'/sendMessage', [
            'chat_id' => $log->recipient,
            'text' => mb_substr($log->body, 0, 4000),
            'disable_web_page_preview' => true,
        ]);
        if (! $response->successful() || ! $response->json('ok')) {
            $log->update(['status' => 'failed', 'error' => 'Telegram: '.($response->json('description') ?? 'HTTP '.$response->status())]);

            return;
        }
        $log->update(['status' => 'sent', 'sent_at' => now()]);
    }
}
