<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\DocumentType;
use App\Models\Worker;
use App\Models\WorkerDocument;
use App\Support\Scans;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    use Concerns;

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        return [
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('organization_id', $this->org($request)->id)],
            'number' => ['nullable', 'string', 'max:80'],
            'issued_at' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'issuer' => ['nullable', 'string', 'max:200'],
            'group_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scan' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.config('permits.scan_max_kb')],
            'remove_scan' => ['sometimes', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function normalize(array $data, DocumentType $type): array
    {
        if (! $type->has_group) {
            $data['group_level'] = null;
        } elseif (empty($data['group_level'])) {
            throw ValidationException::withMessages(['group_level' => 'Укажите группу по электробезопасности (I-V)']);
        }
        if (! empty($data['issued_at']) && ! empty($data['valid_until']) && CarbonImmutable::parse($data['valid_until'])->lt(CarbonImmutable::parse($data['issued_at']))) {
            throw ValidationException::withMessages(['valid_until' => 'Срок действия не может быть раньше даты выдачи']);
        }
        if (empty($data['valid_until']) && $type->validity_months && ! empty($data['issued_at'])) {
            $data['valid_until'] = CarbonImmutable::parse($data['issued_at'])->addMonthsNoOverflow($type->validity_months)->subDay()->toDateString();
        }
        if (empty($data['valid_until']) && $type->validity_months) {
            throw ValidationException::withMessages(['valid_until' => 'Укажите срок действия или дату выдачи']);
        }

        return $data;
    }

    public function store(Request $request, Worker $worker): JsonResponse
    {
        abort_unless($worker->organization_id === $this->org($request)->id, 404, 'Работник не найден');
        $this->requireEdit($request);
        $data = $request->validate($this->rules($request));
        $type = DocumentType::findOrFail($data['document_type_id']);
        $data = $this->normalize($data, $type);
        unset($data['scan'], $data['remove_scan']);
        if ($request->hasFile('scan')) {
            $data = array_merge($data, Scans::storeScan($request->file('scan'), $worker));
        }
        $data['worker_id'] = $worker->id;
        $doc = WorkerDocument::create($data);
        $worker->touch();

        return response()->json(Present::document($doc->load('type'), $this->report($request)), 201);
    }

    public function update(Request $request, WorkerDocument $document): JsonResponse
    {
        $this->own($request, $document);
        $this->requireEdit($request);
        $data = $request->validate($this->rules($request));
        $type = DocumentType::findOrFail($data['document_type_id']);
        $data = $this->normalize($data, $type);
        $remove = (bool) ($data['remove_scan'] ?? false);
        unset($data['scan'], $data['remove_scan']);
        if ($request->hasFile('scan')) {
            Scans::delete($document->scan_path);
            $data = array_merge($data, Scans::storeScan($request->file('scan'), $document->worker));
        } elseif ($remove) {
            Scans::delete($document->scan_path);
            $data = array_merge($data, ['scan_path' => null, 'scan_name' => null, 'scan_mime' => null, 'scan_size' => null]);
        }
        $newUntil = $data['valid_until'] ?? null;
        if (Present::date($document->valid_until) !== Present::date($newUntil)) {
            $data['notified_threshold'] = null;
        }
        $document->update($data);
        $document->worker->touch();

        return response()->json(Present::document($document->fresh('type'), $this->report($request)));
    }

    public function destroy(Request $request, WorkerDocument $document): JsonResponse
    {
        $this->own($request, $document);
        $this->requireEdit($request);
        Scans::delete($document->scan_path);
        $document->delete();

        return response()->json(['deleted' => true]);
    }

    public function scan(Request $request, WorkerDocument $document): StreamedResponse
    {
        $user = $request->user();
        if ($user->isCustomer()) {
            $visible = $document->worker->belongsToMany(\App\Models\AdmissionRequest::class, 'admission_request_workers')
                ->where('customer_id', $user->customer_id)->where('status', '!=', 'draft')->exists();
            abort_unless($visible, 404, 'Документ не найден');
        } else {
            $this->own($request, $document);
        }
        abort_unless($document->scan_path && Storage::disk('local')->exists($document->scan_path), 404, 'Скан не загружен');
        $name = $document->scan_name ?: 'scan.'.Scans::extension($document->scan_path, $document->scan_mime);

        return Storage::disk('local')->response($document->scan_path, $name, [
            'Content-Type' => $document->scan_mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=600',
        ], 'inline');
    }

    private function own(Request $request, WorkerDocument $document): void
    {
        abort_unless($document->worker && $document->worker->organization_id === $this->org($request)->id, 404, 'Документ не найден');
    }
}
