<?php

namespace Tests\Unit;

use App\Models\DocumentType;
use App\Models\WorkerDocument;
use App\Services\Readiness;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    private int $docId = 0;

    private function type(int $id, string $code, bool $group = false): DocumentType
    {
        $t = new DocumentType(['code' => $code, 'name' => strtoupper($code), 'short_name' => $code, 'has_group' => $group]);
        $t->id = $id;

        return $t;
    }

    private function doc(int $typeId, ?string $until, ?int $group = null): WorkerDocument
    {
        $d = new WorkerDocument(['document_type_id' => $typeId, 'valid_until' => $until, 'group_level' => $group, 'number' => 'N'.$typeId]);
        $d->id = ++$this->docId;

        return $d;
    }

    private function reqs(): array
    {
        return [
            ['type' => $this->type(1, 'med'), 'min_group' => null],
            ['type' => $this->type(2, 'eb', true), 'min_group' => 3],
            ['type' => $this->type(3, 'intro'), 'min_group' => null],
        ];
    }

    public function test_document_state_thresholds(): void
    {
        $r = new Readiness([30, 14, 7]);
        $on = CarbonImmutable::parse('2026-11-26');
        $this->assertSame('valid', $r->documentState($on->addDays(31), $on)['state']);
        $s = $r->documentState($on->addDays(30), $on);
        $this->assertSame(['state' => 'expiring', 'days_left' => 30, 'threshold' => 30], $s);
        $this->assertSame(14, $r->documentState($on->addDays(14), $on)['threshold']);
        $this->assertSame(14, $r->documentState($on->addDays(8), $on)['threshold']);
        $this->assertSame(7, $r->documentState($on->addDays(7), $on)['threshold']);
        $this->assertSame(7, $r->documentState($on, $on)['threshold']);
        $this->assertSame('expiring', $r->documentState($on, $on)['state']);
        $this->assertSame('expired', $r->documentState($on->subDay(), $on)['state']);
        $this->assertSame('unlimited', $r->documentState(null, $on)['state']);
    }

    public function test_ready_when_all_documents_valid(): void
    {
        $r = new Readiness();
        $docs = collect([$this->doc(1, '2027-06-01'), $this->doc(2, '2027-05-01', 4), $this->doc(3, null)]);
        $res = $r->evaluateDocuments($docs, $this->reqs(), CarbonImmutable::parse('2026-11-26'));
        $this->assertSame(Readiness::READY, $res['status']);
        $this->assertSame([], $res['problems']);
        $this->assertNull($res['threshold']);
        $this->assertSame(['ok', 'ok', 'ok'], array_column($res['items'], 'state'));
    }

    public function test_missing_expired_and_low_group_make_not_ready(): void
    {
        $r = new Readiness();
        $docs = collect([$this->doc(1, '2026-11-20'), $this->doc(2, '2027-05-01', 2)]);
        $res = $r->evaluateDocuments($docs, $this->reqs(), CarbonImmutable::parse('2026-11-26'));
        $this->assertSame(Readiness::NOT_READY, $res['status']);
        $this->assertSame(['expired', 'low_group', 'missing'], array_column($res['items'], 'state'));
        $this->assertCount(3, $res['problems']);
        $this->assertStringContainsString('нужна не ниже III', $res['problems'][1]);
    }

    public function test_expiring_level_is_smallest_threshold(): void
    {
        $r = new Readiness([30, 14, 7]);
        $on = CarbonImmutable::parse('2026-11-26');
        $docs = collect([$this->doc(1, '2026-12-20'), $this->doc(2, '2026-12-05', 3), $this->doc(3, null)]);
        $res = $r->evaluateDocuments($docs, $this->reqs(), $on);
        $this->assertSame(Readiness::EXPIRING, $res['status']);
        $this->assertSame(9, $res['days_left']);
        $this->assertSame(14, $res['threshold']);
    }

    public function test_best_document_wins_and_renewal_fixes_status(): void
    {
        $r = new Readiness();
        $docs = collect([$this->doc(1, '2026-10-01'), $this->doc(1, '2027-10-01'), $this->doc(2, '2027-01-01', 5), $this->doc(3, null)]);
        $res = $r->evaluateDocuments($docs, $this->reqs(), CarbonImmutable::parse('2026-11-26'));
        $this->assertSame(Readiness::READY, $res['status']);
        $this->assertSame('2027-10-01', $res['items'][0]['valid_until']);
    }

    public function test_period_check_warns_when_document_ends_before_work_end(): void
    {
        $r = new Readiness();
        $docs = collect([$this->doc(1, '2027-03-01'), $this->doc(2, '2027-05-01', 3), $this->doc(3, null)]);
        $res = $r->evaluateDocuments($docs, $this->reqs(), CarbonImmutable::parse('2027-01-10'), CarbonImmutable::parse('2027-03-15'));
        $this->assertSame(Readiness::EXPIRING, $res['status']);
        $this->assertSame('expires_during', $res['items'][0]['state']);
    }

    public function test_days_word(): void
    {
        $this->assertSame('день', Readiness::daysWord(1));
        $this->assertSame('дня', Readiness::daysWord(3));
        $this->assertSame('дней', Readiness::daysWord(11));
        $this->assertSame('день', Readiness::daysWord(21));
        $this->assertSame('дней', Readiness::daysWord(14));
    }
}
