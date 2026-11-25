<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\AdmissionRequest;
use App\Models\Customer;
use App\Models\Facility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use Concerns;

    public function index(): JsonResponse
    {
        $customers = Customer::with('facilities')->orderBy('short_name')->get();

        return response()->json(['data' => $customers->map(fn ($c) => Present::customer($c))]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'short_name' => ['required', 'string', 'max:60'],
            'bin' => ['nullable', 'digits:12'],
            'city' => ['nullable', 'string', 'max:80'],
            'industry' => ['nullable', 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireEdit($request);
        $c = Customer::create($request->validate($this->rules()));

        return response()->json(Present::customer($c->load('facilities')), 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->requireEdit($request);
        $customer->update($request->validate($this->rules()));

        return response()->json(Present::customer($customer->load('facilities')));
    }

    /** @return array<string, mixed> */
    private function facilityRules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'name' => ['required', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function storeFacility(Request $request): JsonResponse
    {
        $this->requireEdit($request);
        $f = Facility::create($request->validate($this->facilityRules()));

        return response()->json(Present::facility($f), 201);
    }

    public function updateFacility(Request $request, Facility $facility): JsonResponse
    {
        $this->requireEdit($request);
        $facility->update($request->validate($this->facilityRules()));

        return response()->json(Present::facility($facility));
    }

    public function destroyFacility(Request $request, Facility $facility): JsonResponse
    {
        $this->requireEdit($request);
        if (AdmissionRequest::where('facility_id', $facility->id)->exists()) {
            abort(409, 'По объекту есть заявки, удалить нельзя');
        }
        $facility->delete();

        return response()->json(['deleted' => true]);
    }
}
