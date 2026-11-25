<?php

namespace App\Services;

use App\Models\AdmissionRequest;
use App\Models\DocumentType;
use App\Models\Organization;
use App\Models\RequirementProfile;
use App\Models\Worker;
use App\Models\WorkerDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ReadinessReport
{
    public readonly Readiness $readiness;

    /** @var array<int, array{type: DocumentType, min_group: int|null}>|null */
    private ?array $basic = null;

    public function __construct(public readonly Organization $organization)
    {
        $this->readiness = new Readiness($organization->thresholds());
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /** @return array<int, array{type: DocumentType, min_group: int|null}> */
    public function basicRequirements(): array
    {
        if ($this->basic === null) {
            $this->basic = DocumentType::where('organization_id', $this->organization->id)
                ->where('is_basic', true)->orderBy('sort')->get()
                ->map(fn (DocumentType $t) => ['type' => $t, 'min_group' => null])->all();
        }

        return $this->basic;
    }

    /** @return array<string, mixed> */
    public function workerBasic(Worker $worker, ?CarbonImmutable $on = null): array
    {
        return $this->readiness->evaluateWorker($worker, $this->basicRequirements(), $on ?? self::today());
    }

    /** @return array<string, mixed> */
    public function workerForProfile(Worker $worker, RequirementProfile $profile, ?CarbonImmutable $on = null, ?CarbonImmutable $until = null): array
    {
        return $this->readiness->evaluateWorker($worker, $profile->requirements(), $on ?? self::today(), $until);
    }

    /** @return array<string, mixed> */
    public function documentState(WorkerDocument $doc, ?CarbonImmutable $on = null): array
    {
        return $this->readiness->documentState($doc->valid_until ? CarbonImmutable::parse($doc->valid_until) : null, $on ?? self::today());
    }

    public function evaluationDate(AdmissionRequest $request): CarbonImmutable
    {
        $start = CarbonImmutable::parse($request->start_date)->startOfDay();
        $today = self::today();

        return $start->lt($today) ? $today : $start;
    }

    /**
     * @return array{status: string, counts: array<string, int>, workers: array<int, array<string, mixed>>, on: string}
     */
    public function forRequest(AdmissionRequest $request): array
    {
        $request->loadMissing(['profile.items.type', 'workers.documents']);
        $on = $this->evaluationDate($request);
        $until = CarbonImmutable::parse($request->end_date)->startOfDay();
        $counts = [Readiness::READY => 0, Readiness::EXPIRING => 0, Readiness::NOT_READY => 0];
        $rows = [];
        foreach ($request->workers as $worker) {
            $res = $this->workerForProfile($worker, $request->profile, $on, $until);
            $counts[$res['status']]++;
            $rows[] = ['worker' => $worker, 'result' => $res];
        }
        $status = $request->workers->isEmpty() ? Readiness::NOT_READY : ($counts[Readiness::NOT_READY] > 0 ? Readiness::NOT_READY : ($counts[Readiness::EXPIRING] > 0 ? Readiness::EXPIRING : Readiness::READY));

        return ['status' => $status, 'counts' => $counts, 'workers' => $rows, 'on' => $on->toDateString()];
    }

    /**
     * @param  Collection<int, Worker>  $workers
     * @return array<string, int>
     */
    public function countFor(Collection $workers, array $requirements, CarbonImmutable $on): array
    {
        $counts = [Readiness::READY => 0, Readiness::EXPIRING => 0, Readiness::NOT_READY => 0];
        foreach ($workers as $w) {
            $counts[$this->readiness->evaluateWorker($w, $requirements, $on)['status']]++;
        }

        return $counts;
    }
}
