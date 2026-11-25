<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\AdmissionRequest;
use App\Models\RequirementProfile;
use App\Models\Worker;
use App\Models\WorkerDocument;
use App\Services\Readiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use Concerns;

    public function __invoke(Request $request): JsonResponse
    {
        $org = $this->org($request);
        $report = $this->report($request);
        $today = $report::today();
        $on = $request->filled('date') ? CarbonImmutable::parse($request->query('date'))->startOfDay() : $today->addDay();
        $workers = Worker::where('organization_id', $org->id)->where('is_active', true)->with('documents.type')
            ->orderBy('last_name')->orderBy('first_name')->get();

        $profiles = RequirementProfile::where('organization_id', $org->id)->with(['items.type', 'customer'])->orderBy('name')->get();
        $profileId = $request->integer('profile_id') ?: null;
        $selected = $profileId ? $profiles->firstWhere('id', $profileId) : null;
        $requirements = $selected ? $selected->requirements() : $report->basicRequirements();

        $rows = [];
        $counts = [Readiness::READY => 0, Readiness::EXPIRING => 0, Readiness::NOT_READY => 0];
        foreach ($workers as $w) {
            $res = $report->readiness->evaluateWorker($w, $requirements, $on);
            $counts[$res['status']]++;
            $rows[] = array_merge(Present::workerBrief($w), ['readiness' => Present::readiness($res)]);
        }
        usort($rows, fn ($a, $b) => [self::rank($a['readiness']['status']), $a['full_name']] <=> [self::rank($b['readiness']['status']), $b['full_name']]);

        $byProfile = $profiles->map(fn (RequirementProfile $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'customer' => $p->customer?->short_name,
            'counts' => $report->countFor($workers, $p->requirements(), $on),
        ])->values();

        $max = $report->readiness->maxThreshold();
        $docs = WorkerDocument::query()
            ->whereIn('worker_id', $workers->pluck('id'))
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', $today->addDays($max)->toDateString())
            ->with(['type', 'worker'])
            ->orderBy('valid_until')
            ->get();
        $latest = WorkerDocument::query()->whereIn('worker_id', $workers->pluck('id'))
            ->selectRaw('worker_id, document_type_id, max(coalesce(valid_until, \'9999-12-31\')) as best')
            ->groupBy('worker_id', 'document_type_id')->get()
            ->mapWithKeys(fn ($r) => [$r->worker_id.':'.$r->document_type_id => $r->best]);
        $expiring = [];
        $expired = [];
        foreach ($docs as $d) {
            $best = $latest[$d->worker_id.':'.$d->document_type_id] ?? null;
            if ($best && $best > $d->valid_until->toDateString()) {
                continue;
            }
            $row = Present::document($d, $report) + ['worker' => ['id' => $d->worker->id, 'full_name' => $d->worker->fullName(), 'position' => $d->worker->position]];
            if ($row['state'] === 'expired') {
                $expired[] = $row;
            } else {
                $expiring[] = $row;
            }
        }
        usort($expired, fn ($a, $b) => strcmp($b['valid_until'], $a['valid_until']));

        $upcoming = AdmissionRequest::where('organization_id', $org->id)
            ->where('end_date', '>=', $today->toDateString())
            ->with(['customer', 'facility', 'profile.items.type', 'workers.documents'])
            ->orderBy('start_date')->limit(6)->get()
            ->map(function (AdmissionRequest $r) use ($report) {
                $rep = $report->forRequest($r);

                return Present::requestBrief($r) + ['workers_count' => $r->workers->count(), 'readiness' => ['status' => $rep['status'], 'counts' => $rep['counts']]];
            });

        return response()->json([
            'date' => $on->toDateString(),
            'today' => $today->toDateString(),
            'profile_id' => $selected?->id,
            'thresholds' => $report->readiness->thresholds(),
            'totals' => [
                'workers' => $workers->count(),
                'ready' => $counts[Readiness::READY],
                'expiring' => $counts[Readiness::EXPIRING],
                'not_ready' => $counts[Readiness::NOT_READY],
                'docs_expiring' => count($expiring),
                'docs_expired' => count($expired),
            ],
            'workers' => $rows,
            'profiles' => $byProfile,
            'expiring' => $expiring,
            'expired' => array_slice($expired, 0, 30),
            'requests' => $upcoming,
        ]);
    }

    private static function rank(string $s): int
    {
        return match ($s) {
            Readiness::NOT_READY => 0,
            Readiness::EXPIRING => 1,
            default => 2,
        };
    }
}
