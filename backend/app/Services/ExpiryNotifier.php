<?php

namespace App\Services;

use App\Jobs\DeliverNotification;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkerDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ExpiryNotifier
{
    /**
     * @return array{organizations: int, items: int, messages: int}
     */
    public function run(?CarbonImmutable $on = null): array
    {
        $on ??= ReadinessReport::today();
        $total = ['organizations' => 0, 'items' => 0, 'messages' => 0];
        foreach (Organization::orderBy('id')->get() as $org) {
            $logs = $this->forOrganization($org, $on);
            if ($logs) {
                $total['organizations']++;
                $total['items'] += count($logs[0]->items ?? []);
                $total['messages'] += count($logs);
            }
        }

        return $total;
    }

    /**
     * @return NotificationLog[]
     */
    public function forOrganization(Organization $org, CarbonImmutable $on): array
    {
        $readiness = new Readiness($org->thresholds());
        $max = $readiness->maxThreshold();
        $docs = WorkerDocument::query()
            ->join('workers', 'workers.id', '=', 'worker_documents.worker_id')
            ->where('workers.organization_id', $org->id)
            ->where('workers.is_active', true)
            ->whereNotNull('worker_documents.valid_until')
            ->where('worker_documents.valid_until', '<=', $on->addDays($max)->toDateString())
            ->select('worker_documents.*')
            ->with(['type', 'worker'])
            ->orderBy('worker_documents.valid_until')
            ->get();
        if ($docs->isEmpty()) {
            return [];
        }
        $newer = DB::table('worker_documents')
            ->whereIn('worker_id', $docs->pluck('worker_id')->unique())
            ->selectRaw('worker_id, document_type_id, max(coalesce(valid_until, \'9999-12-31\')) as best')
            ->groupBy('worker_id', 'document_type_id')->get()
            ->mapWithKeys(fn ($r) => [$r->worker_id.':'.$r->document_type_id => $r->best]);

        $items = [];
        $touched = [];
        foreach ($docs as $doc) {
            $best = $newer[$doc->worker_id.':'.$doc->document_type_id] ?? null;
            if ($best && $best > $doc->valid_until->toDateString()) {
                continue;
            }
            $state = $readiness->documentState(CarbonImmutable::parse($doc->valid_until), $on);
            $threshold = $state['threshold'];
            if ($threshold === null) {
                continue;
            }
            if ($doc->notified_threshold !== null && $doc->notified_threshold <= $threshold) {
                continue;
            }
            $items[] = [
                'document_id' => $doc->id,
                'worker_id' => $doc->worker_id,
                'worker' => $doc->worker->fullName(),
                'position' => $doc->worker->position,
                'document' => $doc->type->name,
                'short' => $doc->type->short_name,
                'number' => $doc->number,
                'valid_until' => $doc->valid_until->toDateString(),
                'days_left' => $state['days_left'],
                'threshold' => $threshold,
            ];
            $touched[$doc->id] = $threshold;
        }
        if (! $items) {
            return [];
        }
        usort($items, fn ($a, $b) => [$a['days_left'], $a['worker']] <=> [$b['days_left'], $b['worker']]);

        $subject = $this->subject($items, $on);
        $text = $this->text($org, $items, $on);
        $logs = [];

        if ($org->notify_email_enabled) {
            $recipients = collect([$org->notify_email])
                ->merge(User::where('organization_id', $org->id)->whereIn('role', [User::HEAD, User::SAFETY])->pluck('email'))
                ->filter()->unique()->values();
            foreach ($recipients as $email) {
                $logs[] = NotificationLog::create([
                    'organization_id' => $org->id, 'channel' => 'email', 'recipient' => $email,
                    'subject' => $subject, 'body' => $text, 'items' => $items, 'status' => 'queued',
                ]);
            }
        }
        if ($org->notify_telegram_enabled) {
            $chat = $org->telegram_chat_id ?: config('permits.telegram.chat_id');
            $logs[] = NotificationLog::create([
                'organization_id' => $org->id, 'channel' => 'telegram', 'recipient' => $chat ?: null,
                'subject' => $subject, 'body' => $text, 'items' => $items, 'status' => 'queued',
            ]);
        }
        if (! $logs) {
            $logs[] = NotificationLog::create([
                'organization_id' => $org->id, 'channel' => 'log', 'recipient' => null,
                'subject' => $subject, 'body' => $text, 'items' => $items, 'status' => 'logged', 'sent_at' => now(),
            ]);
        }

        foreach ($touched as $id => $threshold) {
            WorkerDocument::where('id', $id)->update(['notified_threshold' => $threshold]);
        }
        foreach ($logs as $log) {
            if ($log->status === 'queued') {
                DeliverNotification::dispatch($log->id);
            }
        }

        return array_map(fn ($l) => $l->fresh(), $logs);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function subject(array $items, CarbonImmutable $on): string
    {
        $expired = count(array_filter($items, fn ($i) => $i['days_left'] < 0));
        $soon = count($items) - $expired;
        $parts = [];
        if ($expired) {
            $parts[] = 'просрочено: '.$expired;
        }
        if ($soon) {
            $parts[] = 'истекает: '.$soon;
        }

        return 'Сроки документов на '.$on->format('d.m.Y').' ('.implode(', ', $parts).')';
    }

    /** @param array<int, array<string, mixed>> $items */
    private function text(Organization $org, array $items, CarbonImmutable $on): string
    {
        $lines = [$org->name.': сроки документов работников на '.$on->format('d.m.Y'), ''];
        foreach ($items as $i) {
            $when = $i['days_left'] < 0
                ? 'просрочено с '.CarbonImmutable::parse($i['valid_until'])->format('d.m.Y')
                : ($i['days_left'] === 0 ? 'истекает сегодня' : 'истекает через '.$i['days_left'].' '.Readiness::daysWord($i['days_left']).' ('.CarbonImmutable::parse($i['valid_until'])->format('d.m.Y').')');
            $lines[] = '- '.$i['worker'].', '.$i['short'].($i['number'] ? ' № '.$i['number'] : '').': '.$when;
        }
        $lines[] = '';
        $lines[] = 'Обновите документы в кабинете, чтобы работники не выпали из заявок на допуск.';

        return implode("\n", $lines);
    }
}
