<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\AdmissionRequest;
use App\Models\RequirementProfile;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        $org = $this->org($request);
        $report = $this->report($request);
        $workers = Worker::where('organization_id', $org->id)->where('is_active', true)->with('documents')->get();
        $profiles = RequirementProfile::where('organization_id', $org->id)->with(['items.type', 'customer'])->orderBy('name')->get();
        $on = $report::today()->addDay();

        return response()->json(['data' => $profiles->map(fn ($p) => Present::profile($p) + [
            'counts' => $report->countFor($workers, $p->requirements(), $on),
            'requests_count' => AdmissionRequest::where('requirement_profile_id', $p->id)->count(),
        ])]);
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        $orgId = $this->org($request)->id;

        return [
            'name' => ['required', 'string', 'max:200'],
            'work_type' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.document_type_id' => ['required', 'integer', 'distinct', Rule::exists('document_types', 'id')->where('organization_id', $orgId)],
            'items.*.min_group' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireEdit($request);
        $data = $request->validate($this->rules($request), ['items.required' => 'Выберите хотя бы один документ', 'items.min' => 'Выберите хотя бы один документ']);
        $profile = DB::transaction(function () use ($request, $data) {
            $p = RequirementProfile::create([
                'organization_id' => $this->org($request)->id,
                'name' => $data['name'],
                'work_type' => $data['work_type'],
                'description' => $data['description'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
            ]);
            $this->syncItems($p, $data['items']);

            return $p;
        });

        return response()->json(Present::profile($profile->load(['items.type', 'customer'])), 201);
    }

    public function update(Request $request, RequirementProfile $profile): JsonResponse
    {
        $this->own($request, $profile);
        $this->requireEdit($request);
        $data = $request->validate($this->rules($request), ['items.required' => 'Выберите хотя бы один документ', 'items.min' => 'Выберите хотя бы один документ']);
        DB::transaction(function () use ($profile, $data) {
            $profile->update([
                'name' => $data['name'],
                'work_type' => $data['work_type'],
                'description' => $data['description'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
            ]);
            $this->syncItems($profile, $data['items']);
        });

        return response()->json(Present::profile($profile->fresh(['items.type', 'customer'])));
    }

    public function destroy(Request $request, RequirementProfile $profile): JsonResponse
    {
        $this->own($request, $profile);
        $this->requireEdit($request);
        if (AdmissionRequest::where('requirement_profile_id', $profile->id)->exists()) {
            abort(409, 'Профиль используется в заявках, удалить нельзя');
        }
        $profile->delete();

        return response()->json(['deleted' => true]);
    }

    public function readiness(Request $request, RequirementProfile $profile): JsonResponse
    {
        $this->own($request, $profile);
        $report = $this->report($request);
        $on = $request->filled('date') ? \Carbon\CarbonImmutable::parse($request->query('date')) : $report::today()->addDay();
        $workers = Worker::where('organization_id', $profile->organization_id)->where('is_active', true)->with('documents')->orderBy('last_name')->get();
        $profile->load('items.type');

        return response()->json(['date' => $on->toDateString(), 'data' => $workers->map(fn ($w) => Present::workerBrief($w) + [
            'readiness' => Present::readiness($report->workerForProfile($w, $profile, $on)),
        ])]);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function syncItems(RequirementProfile $profile, array $items): void
    {
        $profile->items()->delete();
        foreach ($items as $item) {
            $profile->items()->create([
                'document_type_id' => $item['document_type_id'],
                'min_group' => $item['min_group'] ?? null,
            ]);
        }
    }

    private function own(Request $request, RequirementProfile $profile): void
    {
        abort_unless($profile->organization_id === $this->org($request)->id, 404, 'Профиль не найден');
    }
}
