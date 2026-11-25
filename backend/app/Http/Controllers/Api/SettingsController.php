<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\ExpiryNotifier;
use App\Services\Readiness;
use App\Services\ReadinessReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    use Concerns;

    public function show(Request $request): JsonResponse
    {
        $org = $this->org($request);

        return response()->json([
            'organization' => Present::organization($org),
            'users' => User::where('organization_id', $org->id)->orderBy('id')->get()->map(fn ($u) => Present::user($u)),
            'telegram_configured' => (bool) config('permits.telegram.token'),
            'sign_mode' => config('permits.sign_mode') === 'ncalayer' ? 'ncalayer' : 'emulate',
            'notify_time' => config('permits.notify_time'),
            'mailpit_url' => config('permits.mailpit_url'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'short_name' => ['nullable', 'string', 'max:80'],
            'bin' => ['required', 'digits:12', function ($attr, $value, $fail) {
                if (! \App\Support\Iin::isValidBin((string) $value)) {
                    $fail('БИН не прошёл проверку контрольной суммы');
                }
            }],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'director' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'notify_email' => ['nullable', 'email', 'max:120'],
            'notify_email_enabled' => ['boolean'],
            'telegram_chat_id' => ['nullable', 'string', 'max:40', 'regex:/^-?\d+$|^@[A-Za-z0-9_]{4,}$/'],
            'notify_telegram_enabled' => ['boolean'],
            'notify_thresholds' => ['required', 'array', 'min:1', 'max:5'],
            'notify_thresholds.*' => ['integer', 'min:1', 'max:365'],
        ], ['telegram_chat_id.regex' => 'Укажите числовой chat_id или @имя канала']);
        $data['notify_thresholds'] = Readiness::normalizeThresholds($data['notify_thresholds']);
        $org = $this->org($request);
        $org->update($data);

        return response()->json(['organization' => Present::organization($org->fresh())]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $this->requireManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'role' => ['required', Rule::in([User::HEAD, User::SAFETY, User::FOREMAN])],
            'position' => ['nullable', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);
        $user = User::create($data + ['organization_id' => $this->org($request)->id, 'email' => mb_strtolower($data['email'])]);

        return response()->json(Present::user($user), 201);
    }

    public function testTelegram(Request $request): JsonResponse
    {
        $this->requireManage($request);
        $org = $this->org($request);
        $token = config('permits.telegram.token');
        $chat = $org->telegram_chat_id ?: config('permits.telegram.chat_id');
        $text = 'Проверка связи: уведомления о сроках документов '.$org->name.' будут приходить сюда.';
        $log = NotificationLog::create([
            'organization_id' => $org->id, 'channel' => 'telegram', 'recipient' => $chat ?: null,
            'subject' => 'Проверка Telegram', 'body' => $text, 'items' => [], 'status' => 'queued',
        ]);
        if (! $token || ! $chat) {
            $log->update(['status' => 'logged', 'sent_at' => now(), 'error' => ! $token ? 'Токен бота не задан в .env, сообщение записано в журнал' : 'Не указан чат Telegram']);

            return response()->json(Present::notification($log->fresh()));
        }
        $r = Http::timeout(15)->post(rtrim(config('permits.telegram.api'), '/').'/bot'.$token.'/sendMessage', ['chat_id' => $chat, 'text' => $text]);
        $log->update($r->successful() && $r->json('ok')
            ? ['status' => 'sent', 'sent_at' => now()]
            : ['status' => 'failed', 'error' => 'Telegram: '.($r->json('description') ?? 'HTTP '.$r->status())]);

        return response()->json(Present::notification($log->fresh()));
    }

    public function notifications(Request $request): JsonResponse
    {
        $q = NotificationLog::where('organization_id', $this->org($request)->id);
        if ($request->filled('channel')) {
            $q->where('channel', $request->query('channel'));
        }
        $page = $q->orderByDesc('id')->paginate(min(100, max(10, $request->integer('per_page', 30))));

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => Present::notification($n)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function runNotifications(Request $request): JsonResponse
    {
        $this->requireEdit($request);
        $logs = app(ExpiryNotifier::class)->forOrganization($this->org($request), ReadinessReport::today());

        return response()->json([
            'created' => count($logs),
            'items' => $logs ? count($logs[0]->items ?? []) : 0,
            'data' => array_map(fn ($l) => Present::notification($l), $logs),
        ]);
    }
}
