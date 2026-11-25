<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\DocumentType;
use App\Models\RequirementProfileItem;
use App\Models\WorkerDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DocumentTypeController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        $org = $this->org($request);
        $types = DocumentType::where('organization_id', $org->id)->orderBy('sort')->orderBy('name')->get();
        $usage = WorkerDocument::whereIn('document_type_id', $types->pluck('id'))
            ->selectRaw('document_type_id, count(*) as c')->groupBy('document_type_id')->pluck('c', 'document_type_id');

        return response()->json(['data' => $types->map(fn ($t) => Present::documentType($t, (int) ($usage[$t->id] ?? 0)))]);
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?DocumentType $type = null): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'short_name' => ['required', 'string', 'max:60'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('document_types', 'code')->where('organization_id', $this->org($request)->id)->ignore($type?->id)],
            'category' => ['required', Rule::in(['med', 'briefing', 'ot', 'special', 'profession', 'ppe'])],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'has_group' => ['boolean'],
            'is_basic' => ['boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireEdit($request);
        $data = $request->validate($this->rules($request));
        $orgId = $this->org($request)->id;
        $data['organization_id'] = $orgId;
        $data['code'] = $data['code'] ?? ('custom_'.Str::lower(Str::random(6)));
        $data['sort'] = $data['sort'] ?? ((int) DocumentType::where('organization_id', $orgId)->max('sort') + 10);
        $type = DocumentType::create($data);

        return response()->json(Present::documentType($type, 0), 201);
    }

    public function update(Request $request, DocumentType $documentType): JsonResponse
    {
        $this->own($request, $documentType);
        $this->requireEdit($request);
        $data = $request->validate($this->rules($request, $documentType));
        if (empty($data['code'])) {
            unset($data['code']);
        }
        if (! isset($data['sort'])) {
            unset($data['sort']);
        }
        $documentType->update($data);

        return response()->json(Present::documentType($documentType->fresh()));
    }

    public function destroy(Request $request, DocumentType $documentType): JsonResponse
    {
        $this->own($request, $documentType);
        $this->requireEdit($request);
        if (WorkerDocument::where('document_type_id', $documentType->id)->exists()) {
            abort(409, 'Этот тип уже используется в документах работников, удалить нельзя');
        }
        RequirementProfileItem::where('document_type_id', $documentType->id)->delete();
        $documentType->delete();

        return response()->json(['deleted' => true]);
    }

    private function own(Request $request, DocumentType $type): void
    {
        abort_unless($type->organization_id === $this->org($request)->id, 404, 'Тип документа не найден');
    }
}
