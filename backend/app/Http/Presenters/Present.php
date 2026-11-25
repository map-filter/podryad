<?php

namespace App\Http\Presenters;

use App\Models\AdmissionRequest;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Facility;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\RequirementProfile;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerDocument;
use App\Services\ReadinessReport;

final class Present
{
    public static function date(mixed $value): ?string
    {
        return $value ? \Carbon\CarbonImmutable::parse($value)->toDateString() : null;
    }

    /** @return array<string, mixed> */
    public static function user(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'role_label' => User::ROLES[$u->role] ?? $u->role,
            'position' => $u->position,
            'phone' => $u->phone,
            'organization_id' => $u->organization_id,
            'customer_id' => $u->customer_id,
            'can_edit' => $u->canEdit(),
            'can_manage' => $u->canManage(),
            'can_sign' => $u->canSign(),
        ];
    }

    /** @return array<string, mixed> */
    public static function organization(Organization $o): array
    {
        return [
            'id' => $o->id,
            'name' => $o->name,
            'short_name' => $o->short_name,
            'bin' => $o->bin,
            'city' => $o->city,
            'address' => $o->address,
            'director' => $o->director,
            'phone' => $o->phone,
            'email' => $o->email,
            'notify_email' => $o->notify_email,
            'notify_email_enabled' => (bool) $o->notify_email_enabled,
            'telegram_chat_id' => $o->telegram_chat_id,
            'notify_telegram_enabled' => (bool) $o->notify_telegram_enabled,
            'notify_thresholds' => $o->thresholds(),
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(Customer $c, bool $withFacilities = true): array
    {
        $data = [
            'id' => $c->id,
            'name' => $c->name,
            'short_name' => $c->short_name,
            'bin' => $c->bin,
            'city' => $c->city,
            'industry' => $c->industry,
            'contact' => $c->contact,
        ];
        if ($withFacilities) {
            $data['facilities'] = $c->facilities->sortBy('name')->values()->map(fn (Facility $f) => self::facility($f))->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function facility(Facility $f): array
    {
        return ['id' => $f->id, 'customer_id' => $f->customer_id, 'name' => $f->name, 'address' => $f->address, 'notes' => $f->notes];
    }

    /** @return array<string, mixed> */
    public static function documentType(DocumentType $t, ?int $usage = null): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'short_name' => $t->short_name,
            'category' => $t->category,
            'validity_months' => $t->validity_months,
            'has_group' => $t->has_group,
            'is_basic' => $t->is_basic,
            'description' => $t->description,
            'sort' => $t->sort,
            'usage' => $usage,
        ];
    }

    /** @return array<string, mixed> */
    public static function profile(RequirementProfile $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'work_type' => $p->work_type,
            'description' => $p->description,
            'customer_id' => $p->customer_id,
            'customer' => $p->customer ? ['id' => $p->customer->id, 'short_name' => $p->customer->short_name, 'name' => $p->customer->name] : null,
            'items' => $p->items->sortBy(fn ($i) => $i->type->sort ?? 0)->values()->map(fn ($i) => [
                'document_type_id' => $i->document_type_id,
                'min_group' => $i->min_group,
                'name' => $i->type->name,
                'short_name' => $i->type->short_name,
                'has_group' => $i->type->has_group,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function document(WorkerDocument $d, ReadinessReport $report): array
    {
        $state = $report->documentState($d);

        return [
            'id' => $d->id,
            'worker_id' => $d->worker_id,
            'document_type_id' => $d->document_type_id,
            'type_name' => $d->type?->name,
            'type_short' => $d->type?->short_name,
            'number' => $d->number,
            'issued_at' => self::date($d->issued_at),
            'valid_until' => self::date($d->valid_until),
            'issuer' => $d->issuer,
            'group_level' => $d->group_level,
            'notes' => $d->notes,
            'has_scan' => (bool) $d->scan_path,
            'scan_name' => $d->scan_name,
            'scan_mime' => $d->scan_mime,
            'scan_size' => $d->scan_size,
            'state' => $state['state'],
            'days_left' => $state['days_left'],
            'threshold' => $state['threshold'],
        ];
    }

    /** @return array<string, mixed> */
    public static function workerBrief(Worker $w): array
    {
        return [
            'id' => $w->id,
            'last_name' => $w->last_name,
            'first_name' => $w->first_name,
            'middle_name' => $w->middle_name,
            'full_name' => $w->fullName(),
            'short_name' => $w->shortName(),
            'iin' => $w->iin,
            'birth_date' => self::date($w->birth_date),
            'position' => $w->position,
            'grade' => $w->grade,
            'site' => $w->site,
            'phone' => $w->phone,
            'hired_at' => self::date($w->hired_at),
            'is_active' => $w->is_active,
            'notes' => $w->notes,
            'has_photo' => (bool) $w->photo_path,
            'photo_version' => $w->updated_at?->getTimestamp(),
        ];
    }

    /** @param array<string, mixed> $res
     *  @return array<string, mixed> */
    public static function readiness(array $res): array
    {
        return [
            'status' => $res['status'],
            'days_left' => $res['days_left'],
            'threshold' => $res['threshold'],
            'problems' => $res['problems'],
            'warnings' => $res['warnings'],
            'items' => $res['items'],
        ];
    }

    /** @return array<string, mixed> */
    public static function requestBrief(AdmissionRequest $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'customer' => $r->customer ? ['id' => $r->customer->id, 'short_name' => $r->customer->short_name, 'name' => $r->customer->name] : null,
            'facility' => $r->facility ? ['id' => $r->facility->id, 'name' => $r->facility->name] : null,
            'profile' => $r->profile ? ['id' => $r->profile->id, 'name' => $r->profile->name] : null,
            'organization' => $r->organization ? ['id' => $r->organization->id, 'name' => $r->organization->name, 'bin' => $r->organization->bin] : null,
            'work_type' => $r->work_type,
            'work_description' => $r->work_description,
            'start_date' => self::date($r->start_date),
            'end_date' => self::date($r->end_date),
            'workers_count' => $r->workers_count ?? $r->workers()->count(),
            'signed_at' => $r->signed_at?->toIso8601String(),
            'signer_subject' => $r->signer_subject,
            'sign_mode' => $r->sign_mode,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'review_comment' => $r->review_comment,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function requestFull(AdmissionRequest $r, ReadinessReport $report, string $currentHash): array
    {
        $rep = $report->forRequest($r);
        $data = self::requestBrief($r);
        $data['workers_count'] = $r->workers->count();
        $data['readiness'] = ['status' => $rep['status'], 'counts' => $rep['counts'], 'on' => $rep['on']];
        $data['signature_valid'] = $r->signed_at !== null && $r->signed_hash === $currentHash;
        $data['reviewer'] = $r->reviewer ? $r->reviewer->name : null;
        $data['workers'] = array_map(fn ($row) => array_merge(self::workerBrief($row['worker']), [
            'readiness' => self::withScans(self::readiness($row['result']), $row['worker']),
            'customer_decision' => $row['worker']->pivot->customer_decision,
            'customer_comment' => $row['worker']->pivot->customer_comment,
        ]), $rep['workers']);

        return $data;
    }

    /** @param array<string, mixed> $res
     *  @return array<string, mixed> */
    public static function withScans(array $res, Worker $worker): array
    {
        $docs = $worker->documents->keyBy('id');
        foreach ($res['items'] as &$item) {
            $doc = $item['document_id'] ? ($docs[$item['document_id']] ?? null) : null;
            $item['has_scan'] = (bool) $doc?->scan_path;
            $item['scan_mime'] = $doc?->scan_mime;
        }

        return $res;
    }

    /** @return array<string, mixed> */
    public static function notification(NotificationLog $n): array
    {
        return [
            'id' => $n->id,
            'channel' => $n->channel,
            'recipient' => $n->recipient,
            'subject' => $n->subject,
            'body' => $n->body,
            'items' => $n->items ?? [],
            'status' => $n->status,
            'error' => $n->error,
            'sent_at' => $n->sent_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
