<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\AdmissionRequest;
use App\Services\ListSigner;
use App\Services\ReadinessReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerPortalController extends Controller
{
    private const VISIBLE = [AdmissionRequest::SUBMITTED, AdmissionRequest::APPROVED, AdmissionRequest::REMARKS];

    public function __construct(private readonly ListSigner $signer) {}

    public function index(Request $request): JsonResponse
    {
        $q = AdmissionRequest::where('customer_id', $request->user()->customer_id)->whereIn('status', self::VISIBLE)
            ->with(['organization', 'customer', 'facility', 'profile.items.type', 'workers.documents']);
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        $list = $q->orderByRaw("case when status = 'submitted' then 0 else 1 end")->orderByDesc('submitted_at')->get()
            ->map(function (AdmissionRequest $r) {
                $rep = (new ReadinessReport($r->organization))->forRequest($r);

                return Present::requestBrief($r) + [
                    'workers_count' => $r->workers->count(),
                    'readiness' => ['status' => $rep['status'], 'counts' => $rep['counts']],
                    'signature_valid' => $r->signed_at !== null && $r->signed_hash === $this->signer->hash($r),
                ];
            });

        return response()->json(['data' => $list]);
    }

    public function show(Request $request, AdmissionRequest $admissionRequest): JsonResponse
    {
        $this->visible($request, $admissionRequest);

        return response()->json($this->full($admissionRequest));
    }

    public function decide(Request $request, AdmissionRequest $admissionRequest): JsonResponse
    {
        $this->visible($request, $admissionRequest);
        if ($admissionRequest->status !== AdmissionRequest::SUBMITTED) {
            abort(409, 'Решение по заявке уже принято');
        }
        $data = $request->validate([
            'decision' => ['required', Rule::in([AdmissionRequest::APPROVED, AdmissionRequest::REMARKS])],
            'comment' => ['nullable', 'string', 'max:3000', 'required_if:decision,remarks'],
            'workers' => ['sometimes', 'array'],
            'workers.*.id' => ['required', 'integer'],
            'workers.*.decision' => ['nullable', Rule::in(['ok', 'remark'])],
            'workers.*.comment' => ['nullable', 'string', 'max:1000'],
        ], ['comment.required_if' => 'Опишите замечания, чтобы подрядчик знал, что исправить']);
        DB::transaction(function () use ($admissionRequest, $data, $request) {
            foreach ($data['workers'] ?? [] as $w) {
                DB::table('admission_request_workers')
                    ->where('admission_request_id', $admissionRequest->id)->where('worker_id', $w['id'])
                    ->update(['customer_decision' => $w['decision'] ?? null, 'customer_comment' => $w['comment'] ?? null]);
            }
            $admissionRequest->update([
                'status' => $data['decision'],
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
                'review_comment' => $data['comment'] ?? null,
            ]);
        });

        return response()->json($this->full($admissionRequest->fresh()));
    }

    public function package(Request $request, AdmissionRequest $admissionRequest): BinaryFileResponse
    {
        $this->visible($request, $admissionRequest);

        return RequestController::packageResponse($admissionRequest);
    }

    /** @return array<string, mixed> */
    private function full(AdmissionRequest $r): array
    {
        $r->load(['customer', 'facility', 'profile.items.type', 'workers.documents.type', 'organization', 'reviewer']);
        $report = new ReadinessReport($r->organization);
        $data = Present::requestFull($r, $report, $this->signer->hash($r));
        $data['profile_items'] = Present::profile($r->profile)['items'];

        return $data;
    }

    private function visible(Request $request, AdmissionRequest $r): void
    {
        abort_unless($r->customer_id === $request->user()->customer_id && in_array($r->status, self::VISIBLE, true), 404, 'Заявка не найдена');
    }
}
