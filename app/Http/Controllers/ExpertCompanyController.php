<?php

namespace App\Http\Controllers;

use App\Models\BusinessEntity;
use App\Models\Customer;
use App\Models\LocationBusinessEntity;
use App\Models\NaceHazardClass;
use App\Services\CompanyService;
use App\Services\CustomerLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Uzman panelindeki "Firmalarım" ekranı: tenant'ın yalnızca company tipindeki firmaları
// ve uzmanın atandığı işyerleri (firma + lokasyon kayıtları).
class ExpertCompanyController extends Controller
{
    public function __construct(
        private CompanyService $companyService,
        private CustomerLocationService $customerLocationService
    ) {
    }

    public function index(): JsonResponse
    {
        $companies = BusinessEntity::query()
            ->where('type', 'company')
            ->with('company')
            ->withCount('locations')
            ->orderBy('name')
            ->get()
            ->map(fn (BusinessEntity $entity) => [
                'business_entity_id' => $entity->id,
                'company_id' => $entity->company?->id,
                'name' => $entity->company?->name ?? $entity->name,
                'short_name' => $entity->company?->short_name,
                'company_type' => $entity->company?->company_type,
                'is_active' => $entity->company?->is_active ?? true,
                'locations_count' => $entity->locations_count,
                'created_at' => $entity->created_at,
            ])
            ->values();

        return response()->json($companies);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'company_type' => ['required', Rule::in(['individual', 'corporate'])],
        ]);

        $this->assertUniqueName($data['name']);

        $company = $this->companyService->create($data);

        return response()->json([
            'business_entity_id' => $company->business_entity_id,
            'company_id' => $company->id,
            'name' => $company->name,
            'short_name' => $company->short_name,
            'company_type' => $company->company_type,
        ], 201);
    }

    public function update(Request $request, BusinessEntity $businessEntity): JsonResponse
    {
        if ($businessEntity->type !== 'company' || $businessEntity->company === null) {
            throw ValidationException::withMessages(['company' => 'Yalnızca firmalar düzenlenebilir.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'company_type' => ['required', Rule::in(['individual', 'corporate'])],
        ]);

        $this->assertUniqueName($data['name'], $businessEntity->id);

        $company = $this->companyService->update($businessEntity->company, $data);

        return response()->json($company);
    }

    // Uzmanın atandığı işyerleri: company tipindeki firma + lokasyon kayıtları.
    public function workplaces(Request $request): JsonResponse
    {
        $locationContext = [];

        foreach (Customer::query()->orderBy('name')->get() as $customer) {
            foreach ($this->customerLocationService->list($customer) as $location) {
                $locationContext[$location['id']] ??= [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'organization_name' => $location['organization_name'],
                ];
            }
        }

        $items = LocationBusinessEntity::query()
            ->whereIn('id', DB::table('location_experts')
                ->where('user_id', $request->user()->id)
                ->select('location_business_entity_id'))
            ->whereHas('businessEntity', fn ($query) => $query->where('type', 'company'))
            ->with(['businessEntity.company', 'location:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(function (LocationBusinessEntity $item) use ($locationContext) {
                $context = $locationContext[$item->location_id] ?? null;
                $experts = DB::table('location_experts')
                    ->join('users', 'users.id', '=', 'location_experts.user_id')
                    ->where('location_experts.location_business_entity_id', $item->id)
                    ->pluck('users.name');

                return [
                    'id' => $item->id,
                    'business_entity_id' => $item->business_entity_id,
                    'company_name' => $item->businessEntity?->company?->name ?? $item->businessEntity?->name,
                    'location_id' => $item->location_id,
                    'location_name' => $item->location?->name,
                    'customer_id' => $context['customer_id'] ?? null,
                    'customer_name' => $context['customer_name'] ?? null,
                    'organization_name' => $context['organization_name'] ?? null,
                    'nace_code' => $item->nace_code,
                    'activity' => $item->activity,
                    'hazard_class' => $item->hazard_class,
                    'sgk_workplace_number' => $item->sgk_workplace_number,
                    'experts' => $experts->values(),
                    'created_at' => $item->created_at,
                ];
            })
            ->values();

        return response()->json($items);
    }

    public function naceHazardClasses(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $items = NaceHazardClass::query()
            ->when($search !== '', fn ($query) => $query
                ->where('nace_code', 'like', $search . '%')
                ->orWhere('activity_name', 'like', '%' . $search . '%'))
            ->orderBy('nace_code')
            ->limit(30)
            ->get(['id', 'nace_code', 'activity_name', 'hazard_class']);

        return response()->json($items);
    }

    // "Arçelik A.Ş", "ARÇELİK A.Ş." ve "arcelik aş" aynı firma sayılır.
    private function assertUniqueName(string $name, ?int $ignoreBusinessEntityId = null): void
    {
        $normalized = self::normalizeName($name);

        $duplicate = BusinessEntity::query()
            ->where('type', 'company')
            ->when($ignoreBusinessEntityId, fn ($query) => $query->whereKeyNot($ignoreBusinessEntityId))
            ->get(['id', 'name'])
            ->first(fn (BusinessEntity $entity) => self::normalizeName($entity->name) === $normalized);

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => "Bu firma zaten kayıtlı: {$duplicate->name}",
            ]);
        }
    }

    private static function normalizeName(string $name): string
    {
        $name = str_replace(['I', 'İ'], ['ı', 'i'], $name);
        $name = mb_strtolower($name, 'UTF-8');
        $name = strtr($name, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);

        return preg_replace('/[^a-z0-9]/', '', $name) ?? '';
    }
}
