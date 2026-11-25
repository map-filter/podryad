<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><title>{{ $log->subject }}</title></head>
<body style="margin:0;padding:24px;background:#f3f5f8;font-family:Arial,Helvetica,sans-serif;color:#1d2733">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #dde3ea;border-radius:8px">
<tr><td style="padding:18px 22px;border-bottom:1px solid #dde3ea">
<div style="font-size:13px;color:#6b7785">Кабинет допуска подрядчика</div>
<div style="font-size:18px;font-weight:bold;margin-top:4px">{{ $log->subject }}</div>
</td></tr>
<tr><td style="padding:16px 22px">
<table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:14px">
<tr style="background:#eef2f7;text-align:left"><th>Работник</th><th>Документ</th><th>Действует до</th><th>Статус</th></tr>
@foreach ($items as $i)
<tr style="border-bottom:1px solid #e6ebf0">
<td>{{ $i['worker'] }}<br><span style="color:#6b7785;font-size:12px">{{ $i['position'] }}</span></td>
<td>{{ $i['short'] }}@if($i['number']) № {{ $i['number'] }}@endif</td>
<td>{{ \Carbon\CarbonImmutable::parse($i['valid_until'])->format('d.m.Y') }}</td>
<td style="color:{{ $i['days_left'] < 0 ? '#b2302b' : ($i['days_left'] <= 7 ? '#c2410c' : '#8a5a00') }};font-weight:bold">
@if ($i['days_left'] < 0) просрочено @elseif ($i['days_left'] === 0) сегодня @else через {{ $i['days_left'] }} {{ \App\Services\Readiness::daysWord($i['days_left']) }} @endif
</td></tr>
@endforeach
</table>
<p style="font-size:14px;margin:16px 0 0">Обновите документы в кабинете, чтобы работники не выпали из заявок на допуск.</p>
<p style="margin:16px 0 0"><a href="{{ $url }}/workers" style="display:inline-block;background:#1f4e79;color:#fff;text-decoration:none;padding:9px 16px;border-radius:6px">Открыть кабинет</a></p>
</td></tr>
</table>
</body>
</html>
