<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Worker;
use App\Rules\ValidIin;
use App\Support\Iin;
use App\Support\Scans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkerController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        $org = $this->org($request);
        $report = $this->report($request);
        $q = Worker::where('organization_id', $org->id)->with('documents.type');
        if ($request->filled('search')) {
            $s = '%'.mb_strtolower(trim((string) $request->query('search'))).'%';
            $q->where(function ($w) use ($s) {
                $w->whereRaw('lower(last_name) like ?', [$s])
                    ->orWhereRaw('lower(first_name) like ?', [$s])
                    ->orWhereRaw('lower(coalesce(middle_name, \'\')) like ?', [$s])
                    ->orWhere('iin', 'like', $s);
            });
        }
        foreach (['site', 'position'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        $active = $request->query('active', '1');
        if ($active !== 'all') {
            $q->where('is_active', $active === '1');
        }
        $workers = $q->orderBy('last_name')->orderBy('first_name')->get();
        $rows = [];
        foreach ($workers as $w) {
            $res = $report->workerBasic($w);
            $expiringDocs = [];
            $min = null;
            foreach ($w->documents as $d) {
                $st = $report->documentState($d);
                if (in_array($st['state'], ['expiring', 'expired'], true)) {
                    $expiringDocs[] = ['type_short' => $d->type->short_name, 'state' => $st['state'], 'days_left' => $st['days_left'], 'valid_until' => Present::date($d->valid_until)];
                }
                if ($st['days_left'] !== null) {
                    $min = $min === null ? $st['days_left'] : min($min, $st['days_left']);
                }
            }
            $rows[] = array_merge(Present::workerBrief($w), [
                'documents_count' => $w->documents->count(),
                'readiness' => Present::readiness($res),
                'attention' => $expiringDocs,
                'nearest_days' => $min,
            ]);
        }
        $status = $request->query('status');
        if (in_array($status, ['ready', 'expiring', 'not_ready'], true)) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['readiness']['status'] === $status));
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'sites' => Worker::where('organization_id', $org->id)->whereNotNull('site')->distinct()->orderBy('site')->pluck('site'),
                'positions' => Worker::where('organization_id', $org->id)->distinct()->orderBy('position')->pluck('position'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?Worker $worker = null): array
    {
        $orgId = $this->org($request)->id;

        return [
            'last_name' => ['required', 'string', 'max:80'],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'iin' => ['required', 'string', new ValidIin, Rule::unique('workers', 'iin')->where('organization_id', $orgId)->ignore($worker?->id)],
            'position' => ['required', 'string', 'max:120'],
            'grade' => ['nullable', 'integer', 'min:1', 'max:8'],
            'site' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'hired_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireEdit($request);
        $data = $request->validate($this->rules($request), ['iin.unique' => 'Работник с таким ИИН уже есть']);
        $data['organization_id'] = $this->org($request)->id;
        $data['birth_date'] = Iin::birthDate($data['iin'])?->toDateString();
        $worker = Worker::create($data);

        return response()->json($this->card($request, $worker->fresh()), 201);
    }

    public function show(Request $request, Worker $worker): JsonResponse
    {
        $this->own($request, $worker);

        return response()->json($this->card($request, $worker));
    }

    public function update(Request $request, Worker $worker): JsonResponse
    {
        $this->own($request, $worker);
        $this->requireEdit($request);
        $data = $request->validate($this->rules($request, $worker), ['iin.unique' => 'Работник с таким ИИН уже есть']);
        $data['birth_date'] = Iin::birthDate($data['iin'])?->toDateString();
        $worker->update($data);

        return response()->json($this->card($request, $worker->fresh()));
    }

    public function destroy(Request $request, Worker $worker): JsonResponse
    {
        $this->own($request, $worker);
        $this->requireEdit($request);
        if ($worker->is_active) {
            $worker->update(['is_active' => false]);

            return response()->json(['archived' => true]);
        }
        foreach ($worker->documents as $d) {
            Scans::delete($d->scan_path);
        }
        Scans::delete($worker->photo_path);
        $worker->delete();

        return response()->json(['deleted' => true]);
    }

    public function uploadPhoto(Request $request, Worker $worker): JsonResponse
    {
        $this->own($request, $worker);
        $this->requireEdit($request);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $path = Scans::storePhoto($request->file('photo'), $worker);
        Scans::delete($worker->photo_path);
        $worker->update(['photo_path' => $path]);

        return response()->json($this->card($request, $worker->fresh()));
    }

    public function photo(Request $request, Worker $worker): StreamedResponse
    {
        $this->ownOrCustomer($request, $worker);
        abort_unless($worker->photo_path && Storage::disk('local')->exists($worker->photo_path), 404, 'Фото не загружено');

        return Storage::disk('local')->response($worker->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /** @return array<string, mixed> */
    private function card(Request $request, Worker $worker): array
    {
        $report = $this->report($request);
        $worker->load('documents.type');
        $docs = $worker->documents->sortBy(fn ($d) => [$d->type->sort, $d->valid_until?->getTimestamp() ?? PHP_INT_MAX])->values();
        $requests = $worker->belongsToMany(\App\Models\AdmissionRequest::class, 'admission_request_workers')
            ->with('customer')->orderByDesc('start_date')->limit(10)->get()
            ->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'customer' => $r->customer->short_name, 'start_date' => Present::date($r->start_date), 'end_date' => Present::date($r->end_date)]);

        return array_merge(Present::workerBrief($worker), [
            'readiness' => Present::readiness($report->workerBasic($worker)),
            'readiness_tomorrow' => Present::readiness($report->workerBasic($worker, $report::today()->addDay())),
            'documents' => $docs->map(fn ($d) => Present::document($d, $report))->all(),
            'requests' => $requests,
            'gender' => Iin::gender($worker->iin),
        ]);
    }

    private function own(Request $request, Worker $worker): void
    {
        abort_unless($worker->organization_id === $this->org($request)->id, 404, 'Работник не найден');
    }

    private function ownOrCustomer(Request $request, Worker $worker): void
    {
        $user = $request->user();
        if ($user->isCustomer()) {
            $visible = $worker->belongsToMany(\App\Models\AdmissionRequest::class, 'admission_request_workers')
                ->where('customer_id', $user->customer_id)->where('status', '!=', 'draft')->exists();
            abort_unless($visible, 404, 'Работник не найден');

            return;
        }
        $this->own($request, $worker);
    }
}
