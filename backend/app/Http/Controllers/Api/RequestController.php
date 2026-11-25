<?php

namespace App\Http\Controllers\Api;

use App\Exports\AdmissionPackage;
use App\Exports\PassListXlsx;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\AdmissionRequest;
use App\Models\Facility;
use App\Models\RequirementProfile;
use App\Models\Worker;
use App\Services\ListSigner;
use App\Services\Readiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RequestController extends Controller
{
    use Concerns;

    public function __construct(private readonly ListSigner $signer) {}

    public function index(Request $request): JsonResponse
    {
        $org = $this->org($request);
        $report = $this->report($request);
        $q = AdmissionRequest::where('organization_id', $org->id)
            ->with(['customer', 'facility', 'profile.items.type', 'workers.documents']);
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        $list = $q->orderByDesc('start_date')->orderByDesc('id')->get()->map(function (AdmissionRequest $r) use ($report) {
            $rep = $report->forRequest($r);

            return Present::requestBrief($r) + [
                'workers_count' => $r->workers->count(),
                'readiness' => ['status' => $rep['status'], 'counts' => $rep['counts']],
                'signature_valid' => $r->signed_at !== null && $r->signed_hash === $this->signer->hash($r),
            ];
        });

        return response()->json(['data' => $list]);
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        $orgId = $this->org($request)->id;

        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'requirement_profile_id' => ['required', 'integer', Rule::exists('requirement_profiles', 'id')->where('organization_id', $orgId)],
            'work_type' => ['nullable', 'string', 'max:200'],
            'work_description' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'worker_ids' => ['sometimes', 'array'],
            'worker_ids.*' => ['integer', 'distinct', Rule::exists('workers', 'id')->where('organization_id', $orgId)],
        ];
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function fill(array $data): array
    {
        if (! empty($data['facility_id'])) {
            $f = Facility::find($data['facility_id']);
            if ($f->customer_id !== (int) $data['customer_id']) {
                throw ValidationException::withMessages(['facility_id' => 'Объект относится к другому заказчику']);
            }
        }
        $profile = RequirementProfile::find($data['requirement_profile_id']);
        if (empty($data['work_type'])) {
            $data['work_type'] = $profile->work_type;
        }

        return $data;
    }

    public function store(Request $request): JsonResponse
    {
        $org = $this->org($request);
        $data = $this->fill($request->validate($this->rules($request), ['end_date.after_or_equal' => 'Дата окончания не может быть раньше начала']));
        $r = DB::transaction(function () use ($org, $data, $request) {
            $year = CarbonImmutable::parse($data['start_date'])->year;
            \App\Models\Organization::whereKey($org->id)->lockForUpdate()->first();
            $last = AdmissionRequest::where('organization_id', $org->id)->where('number', 'like', 'ДП-'.$year.'-%')->pluck('number')
                ->map(fn ($n) => (int) substr($n, strrpos($n, '-') + 1))->max() ?? 0;
            $seq = $last + 1;
            $r = AdmissionRequest::create([
                'organization_id' => $org->id,
                'number' => sprintf('ДП-%d-%03d', $year, $seq),
                'customer_id' => $data['customer_id'],
                'facility_id' => $data['facility_id'] ?? null,
                'requirement_profile_id' => $data['requirement_profile_id'],
                'work_type' => $data['work_type'],
                'work_description' => $data['work_description'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => AdmissionRequest::DRAFT,
                'created_by' => $request->user()->id,
            ]);
            $r->workers()->sync($data['worker_ids'] ?? []);

            return $r;
        });

        return response()->json($this->full($request, $r->fresh()), 201);
    }

    public function show(Request $request, AdmissionRequest $admissionRequest): JsonResponse
    {
        $this->own($request, $admissionRequest);

        return response()->json($this->full($request, $admissionRequest));
    }

    public function update(Request $request, AdmissionRequest $admissionRequest): JsonResponse
    {
        $this->own($request, $admissionRequest);
        $this->editable($admissionRequest);
        $data = $this->fill($request->validate($this->rules($request), ['end_date.after_or_equal' => 'Дата окончания не может быть раньше начала']));
        DB::transaction(function () use ($admissionRequest, $data) {
            $admissionRequest->update(collect($data)->except('worker_ids')->all());
            if (array_key_exists('worker_ids', $data)) {
                $admissionRequest->workers()->sync($data['worker_ids']);
            }
        });

        return response()->json($this->full($request, $admissionRequest->fresh()));
    }

    public function setWorkers(Request $request, AdmissionRequest $admissionRequest): JsonResponse
    {
        $this->own($request, $admissionRequest);
        $this->editable($admissionRequest);
        $orgId = $this->org($request)->id;
        $data = $request->validate([
            'add' => ['sometimes', 'array'],
            'add.*' => ['integer', Rule::exists('workers', 'id')->where('organization_id', $orgId)],
            'remove' => ['sometimes', 'array'],
            'remove.*' => ['integer'],
        ]);
        if (! empty($data['add'])) {
            $admissionRequest->workers()->syncWithoutDetaching($data['add']);
        }
        if (! empty($data['remove'])) {
            $admissionRequest->workers()->detach($data['remove']);
        }

        return response()->json($this->full($request, $admissionRequest->fresh()));
    }

    public function destroy(Request $request, AdmissionRequest $admissionRequest): JsonResponse
    {
        $this->own($request, $admissionRequest);
        if ($admissionRequest->status !== AdmissionRequest::DRAFT) {
            abort(409, 'Удалить можно только черновик');
        }
        $admissionRequest->delete();

        return response()->json(['deleted' => true]);
    }

    public function signData(Request $request, AdmissionRequest $admissionRequest): JsonResponse
    {
        $this->own($request, $admissionRequest);
        $this->ensureSignable($request, $admissionRequest);
        $text = $this->signer->payloadText($admissionRequest);

        return response()->json([
            'data' => $text,
            'data_base64' => base64_encode($text),
            'hash' => hash('sha256', $text),
            'sign_mode' => $this->signer->mode(),
        ]);
    }

    public function sign(Request $request, AdmissionRequest $admissionRequest): JsonResponse
    {
        $this->own($request, $admissionRequest);
        $this->ensureSignable($request, $admissionRequest);
        $data = $request->validate(['signature' => ['required', 'string', 'max:200000']]);
        $text = $this->signer->payloadText($admissionRequest);
        try {
            $res = $this->signer->verify($data['signature'], $text, $this->org($request)->bin);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['signature' => $e->getMessage()]);
        }
        $admissionRequest->update([
            'signature' => trim($data['signature']),
            'signed_hash' => hash('sha256', $text),
            'signed_at' => now(),
            'signed_by' => $request->user()->id,
            'sign_mode' => $res['mode'],
            'signer_subject' => $res['subject'],
        ]);

        return response()->json($this->full($request, $admissionRequest->fresh()));
    }

    public function emulateSign(Request $request): JsonResponse
    {
        if ($this->signer->mode() !== 'emulate') {
            abort(409, 'Эмуляция подписи выключена, используйте NCALayer');
        }
        $data = $request->validate(['data' => ['required', 'string']]);
        try {
            return response()->json(['signature' => $this->signer->emulate($data['data'], $request->user())]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['data' => $e->getMessage()]);
        }
    }

    public function submit(Request $request, AdmissionRequest $admissionRequest): JsonResponse
    {
        $this->own($request, $admissionRequest);
        $this->editable($admissionRequest);
        if (! $request->user()->canSign()) {
            abort(403, 'Подать заявку могут руководитель и специалист по ОТ');
        }
        if (! $admissionRequest->signed_at || $admissionRequest->signed_hash !== $this->signer->hash($admissionRequest)) {
            abort(409, 'Сначала подпишите список ЭЦП: после подписи список менялся или ещё не подписан');
        }
        $admissionRequest->update(['status' => AdmissionRequest::SUBMITTED, 'submitted_at' => now()]);
        DB::table('admission_request_workers')->where('admission_request_id', $admissionRequest->id)
            ->update(['customer_decision' => null, 'customer_comment' => null]);

        return response()->json($this->full($request, $admissionRequest->fresh()));
    }

    public function package(Request $request, AdmissionRequest $admissionRequest): BinaryFileResponse
    {
        $this->own($request, $admissionRequest);

        return $this->packageResponse($admissionRequest);
    }

    public function summaryPdf(Request $request, AdmissionRequest $admissionRequest): \Illuminate\Http\Response
    {
        $this->own($request, $admissionRequest);
        $pdf = (new AdmissionPackage($admissionRequest))->pdf();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => AdmissionPackage::disposition('Список_'.$admissionRequest->number.'.pdf', 'inline'),
        ]);
    }

    public function passList(Request $request, AdmissionRequest $admissionRequest): BinaryFileResponse
    {
        $this->own($request, $admissionRequest);
        $path = (new PassListXlsx($admissionRequest))->build();

        return response()->download($path, 'Пропуск_'.$admissionRequest->number.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public static function packageResponse(AdmissionRequest $r): BinaryFileResponse
    {
        $path = (new AdmissionPackage($r))->zip();

        return response()->download($path, 'Пакет_допуска_'.$r->number.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function full(Request $request, AdmissionRequest $r): array
    {
        $r->load(['customer', 'facility', 'profile.items.type', 'workers.documents', 'organization', 'reviewer']);

        return Present::requestFull($r, $this->report($request), $this->signer->hash($r)) + [
            'profile_items' => Present::profile($r->profile)['items'],
        ];
    }

    private function own(Request $request, AdmissionRequest $r): void
    {
        abort_unless($r->organization_id === $this->org($request)->id, 404, 'Заявка не найдена');
    }

    private function editable(AdmissionRequest $r): void
    {
        if (! $r->isEditable()) {
            abort(409, 'Заявка уже подана заказчику, изменить её нельзя');
        }
    }

    private function ensureSignable(Request $request, AdmissionRequest $r): void
    {
        $this->editable($r);
        if (! $request->user()->canSign()) {
            abort(403, 'Подписывать списки могут руководитель и специалист по ОТ');
        }
        $rep = $this->report($request)->forRequest($r);
        if ($r->workers->isEmpty()) {
            abort(409, 'В заявке нет работников');
        }
        if ($rep['counts'][Readiness::NOT_READY] > 0) {
            abort(409, 'Не все работники готовы: уберите их из списка или обновите документы');
        }
    }
}
