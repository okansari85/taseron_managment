<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\BusinessEntity;
use App\Models\Customer;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * KSY-SIS yapısı: ana firmanın firmaları (ticari unvanlar). İşyeri yalnız bu listedeki firmalarla açılır.
 * Liste: ana firmaya ait firmalar + bu ana firmada işyeri olan firmalar (eski kayıtlar).
 */
class CustomerCompanyController extends Controller
{
    public function __construct(private CompanyService $companyService, private TenantContext $tenantContext)
    {
    }

    public function index(Customer $customer): JsonResponse
    {
        $this->assertTenant($customer);
        $counts = DB::table('location_business_entities')->where('customer_id', $customer->id)
            ->groupBy('business_entity_id')->selectRaw('business_entity_id, count(*) as total')->pluck('total', 'business_entity_id');

        $items = BusinessEntity::query()
            ->where('type', 'company')
            ->where(fn ($query) => $query->where('customer_id', $customer->id)->orWhereIn('id', $counts->keys()->all()))
            ->with('company')
            ->orderBy('name')
            ->get()
            ->map(fn (BusinessEntity $entity) => [
                'business_entity_id' => $entity->id,
                'name' => $entity->company?->name ?? $entity->name,
                'company_type' => $entity->company?->company_type,
                'workplaces_count' => (int) ($counts[$entity->id] ?? 0),
            ]);

        return response()->json($items->values());
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->assertTenant($customer);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_type' => ['nullable', Rule::in(['individual', 'corporate'])],
        ]);
        $name = trim($data['name']);
        $normalized = ExpertCompanyController::normalizeName($name);

        // Aynı unvan hesapta varsa: sahipsizse bu ana firmaya geçer, başka ana firmanınsa eklenmez.
        $existing = BusinessEntity::query()->where('type', 'company')->get(['id', 'name', 'customer_id'])
            ->first(fn (BusinessEntity $entity) => ExpertCompanyController::normalizeName($entity->name) === $normalized);
        if ($existing) {
            if ($existing->customer_id && (int) $existing->customer_id !== (int) $customer->id) {
                throw ValidationException::withMessages(['name' => "\"{$existing->name}\" başka bir ana firmaya ait."]);
            }
            DB::table('business_entities')->where('id', $existing->id)->update(['customer_id' => $customer->id]);

            return response()->json(['business_entity_id' => $existing->id, 'name' => $existing->name], 201);
        }

        $company = $this->companyService->create(['name' => $name, 'company_type' => $data['company_type'] ?? 'corporate']);
        DB::table('business_entities')->where('id', $company->business_entity_id)->update(['customer_id' => $customer->id]);

        return response()->json(['business_entity_id' => $company->business_entity_id, 'name' => $company->name], 201);
    }

    // Firma yalnız bu ana firmadan çıkarılır (kayıt silinmez); bu ana firmada işyeri varsa çıkarılmaz.
    public function destroy(Customer $customer, BusinessEntity $businessEntity): JsonResponse
    {
        $this->assertTenant($customer);
        if (DB::table('location_business_entities')->where('customer_id', $customer->id)->where('business_entity_id', $businessEntity->id)->exists()) {
            throw ValidationException::withMessages(['business_entity' => 'Bu firmanın bu ana firmada işyeri var; önce işyerlerini kaldırın.']);
        }
        DB::table('business_entities')->where('id', $businessEntity->id)->where('customer_id', $customer->id)->update(['customer_id' => null]);

        return response()->json(['message' => 'Firma ana firmadan çıkarıldı.']);
    }

    private function assertTenant(Customer $customer): void
    {
        abort_unless((int) $customer->tenant_id === (int) $this->tenantContext->id(), 404);
    }
}
