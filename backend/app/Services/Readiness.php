<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\Worker;
use App\Models\WorkerDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class Readiness
{
    public const READY = 'ready';

    public const EXPIRING = 'expiring';

    public const NOT_READY = 'not_ready';

    public const DEFAULT_THRESHOLDS = [30, 14, 7];

    /** @var int[] */
    private array $thresholds;

    /**
     * @param  int[]|null  $thresholds
     */
    public function __construct(?array $thresholds = null)
    {
        $this->thresholds = self::normalizeThresholds($thresholds ?? self::DEFAULT_THRESHOLDS);
    }

    /**
     * @param  array<int|string>  $values
     * @return int[]
     */
    public static function normalizeThresholds(array $values): array
    {
        $list = array_values(array_unique(array_filter(array_map('intval', $values), fn ($v) => $v > 0 && $v <= 365)));
        rsort($list);

        return $list ?: self::DEFAULT_THRESHOLDS;
    }

    /** @return int[] */
    public function thresholds(): array
    {
        return $this->thresholds;
    }

    public function maxThreshold(): int
    {
        return $this->thresholds[0];
    }

    public function thresholdFor(?int $daysLeft): ?int
    {
        if ($daysLeft === null) {
            return null;
        }
        if ($daysLeft < 0) {
            return 0;
        }
        $hit = null;
        foreach ($this->thresholds as $t) {
            if ($daysLeft <= $t) {
                $hit = $t;
            }
        }

        return $hit;
    }

    /**
     * @return array{state: string, days_left: int|null, threshold: int|null}
     */
    public function documentState(?CarbonImmutable $validUntil, CarbonImmutable $on): array
    {
        if ($validUntil === null) {
            return ['state' => 'unlimited', 'days_left' => null, 'threshold' => null];
        }
        $days = (int) $on->startOfDay()->diffInDays($validUntil->startOfDay(), false);
        if ($days < 0) {
            return ['state' => 'expired', 'days_left' => $days, 'threshold' => 0];
        }
        $threshold = $this->thresholdFor($days);

        return ['state' => $threshold === null ? 'valid' : 'expiring', 'days_left' => $days, 'threshold' => $threshold];
    }

    /**
     * @param  Collection<int, WorkerDocument>  $documents
     * @param  array<int, array{type: DocumentType, min_group: int|null}>  $requirements
     * @return array<string, mixed>
     */
    public function evaluateDocuments(Collection $documents, array $requirements, CarbonImmutable $on, ?CarbonImmutable $until = null): array
    {
        $items = [];
        $status = self::READY;
        $minDays = null;
        $minThreshold = null;
        $problems = [];
        $warnings = [];

        foreach ($requirements as $req) {
            $type = $req['type'];
            $minGroup = $req['min_group'];
            $ofType = $documents->where('document_type_id', $type->id);
            $label = $type->name.($minGroup ? ', группа не ниже '.self::roman($minGroup) : '');
            $item = [
                'document_type_id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'short_name' => $type->short_name,
                'min_group' => $minGroup,
                'document_id' => null,
                'number' => null,
                'valid_until' => null,
                'group_level' => null,
                'days_left' => null,
                'state' => 'missing',
                'message' => 'нет документа: '.$label,
            ];

            if ($ofType->isEmpty()) {
                $items[] = $item;
                $problems[] = $item['message'];
                $status = self::NOT_READY;

                continue;
            }

            $eligible = $minGroup ? $ofType->filter(fn (WorkerDocument $d) => (int) $d->group_level >= $minGroup) : $ofType;
            if ($eligible->isEmpty()) {
                $best = $this->pickBest($ofType);
                $item = array_merge($item, $this->docFields($best), [
                    'state' => 'low_group',
                    'message' => $type->name.': группа '.self::roman((int) $best->group_level).', нужна не ниже '.self::roman($minGroup),
                ]);
                $items[] = $item;
                $problems[] = $item['message'];
                $status = self::NOT_READY;

                continue;
            }

            $best = $this->pickBest($eligible);
            $validUntil = $best->valid_until ? CarbonImmutable::parse($best->valid_until) : null;
            $ds = $this->documentState($validUntil, $on);
            $item = array_merge($item, $this->docFields($best), ['days_left' => $ds['days_left']]);

            if ($ds['state'] === 'expired') {
                $item['state'] = 'expired';
                $item['message'] = $type->name.': просрочено с '.$validUntil->format('d.m.Y');
                $problems[] = $item['message'];
                $status = self::NOT_READY;
            } elseif ($until !== null && $validUntil !== null && $validUntil->lt($until->startOfDay())) {
                $item['state'] = 'expires_during';
                $item['message'] = $type->name.': истекает '.$validUntil->format('d.m.Y').', до окончания работ';
                $warnings[] = $item['message'];
                if ($status === self::READY) {
                    $status = self::EXPIRING;
                }
            } elseif ($ds['state'] === 'expiring') {
                $item['state'] = 'expiring';
                $item['message'] = $type->name.': истекает через '.$ds['days_left'].' '.self::daysWord($ds['days_left']);
                $warnings[] = $item['message'];
                if ($status === self::READY) {
                    $status = self::EXPIRING;
                }
            } else {
                $item['state'] = 'ok';
                $item['message'] = $ds['state'] === 'unlimited' ? $type->name.': бессрочно' : $type->name.': до '.$validUntil->format('d.m.Y');
            }

            if ($ds['days_left'] !== null && $ds['days_left'] >= 0) {
                $minDays = $minDays === null ? $ds['days_left'] : min($minDays, $ds['days_left']);
            }
            $items[] = $item;
        }

        if ($status !== self::NOT_READY && $minDays !== null) {
            $minThreshold = $this->thresholdFor($minDays);
        }

        return [
            'status' => $status,
            'days_left' => $minDays,
            'threshold' => $status === self::NOT_READY ? null : $minThreshold,
            'problems' => $problems,
            'warnings' => $warnings,
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, array{type: DocumentType, min_group: int|null}>  $requirements
     * @return array<string, mixed>
     */
    public function evaluateWorker(Worker $worker, array $requirements, CarbonImmutable $on, ?CarbonImmutable $until = null): array
    {
        $documents = $worker->relationLoaded('documents') ? $worker->documents : $worker->documents()->get();

        return $this->evaluateDocuments($documents, $requirements, $on, $until);
    }

    /**
     * @param  Collection<int, WorkerDocument>  $documents
     */
    private function pickBest(Collection $documents): WorkerDocument
    {
        return $documents->sort(function (WorkerDocument $a, WorkerDocument $b) {
            if ($a->valid_until === null && $b->valid_until === null) {
                return $b->id <=> $a->id;
            }
            if ($a->valid_until === null) {
                return -1;
            }
            if ($b->valid_until === null) {
                return 1;
            }
            $cmp = CarbonImmutable::parse($b->valid_until)->getTimestamp() <=> CarbonImmutable::parse($a->valid_until)->getTimestamp();

            return $cmp !== 0 ? $cmp : $b->id <=> $a->id;
        })->first();
    }

    /** @return array<string, mixed> */
    private function docFields(WorkerDocument $d): array
    {
        return [
            'document_id' => $d->id,
            'number' => $d->number,
            'valid_until' => $d->valid_until ? CarbonImmutable::parse($d->valid_until)->toDateString() : null,
            'group_level' => $d->group_level,
        ];
    }

    public static function roman(int $n): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'][$n] ?? (string) $n;
    }

    public static function daysWord(int $n): string
    {
        $n = abs($n);
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return 'день';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return 'дня';
        }

        return 'дней';
    }
}
