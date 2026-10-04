<?php

namespace App\Http\Controllers;

use App\Services\PkBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Süper admin: paket tanımları, hesaplara paket atama, elle kredi ve hesabın kredi hareketleri.
class PkAdminBillingController extends Controller
{
    public function __construct(private PkBillingService $billing)
    {
    }

    public function packages(): JsonResponse
    {
        return response()->json(DB::table('pk_packages')->orderBy('account_type')->orderBy('sort_order')->orderBy('id')->get());
    }

    public function storePackage(Request $request): JsonResponse
    {
        $data = $this->validatePackage($request);
        $id = DB::table('pk_packages')->insertGetId($data + [
            'sort_order' => (int) DB::table('pk_packages')->where('account_type', $data['account_type'])->max('sort_order') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(DB::table('pk_packages')->find($id), 201);
    }

    // Paketi değiştirmek atanmış hesapları değiştirmez (hesapta atama anındaki değerler); yeniden atanınca geçer.
    public function updatePackage(Request $request, int $package): JsonResponse
    {
        abort_unless(DB::table('pk_packages')->where('id', $package)->exists(), 404);
        DB::table('pk_packages')->where('id', $package)->update($this->validatePackage($request) + ['updated_at' => now()]);

        return response()->json(DB::table('pk_packages')->find($package));
    }

    /** pktakip hesapları ve paket özetleri. */
    public function accounts(): JsonResponse
    {
        $tenants = DB::table('tenants')->whereIn('tenant_type', PkBillingService::ACCOUNT_TYPES)->orderBy('name')->get(['id', 'name', 'tenant_type']);

        return response()->json($tenants->map(fn ($tenant) => ['id' => $tenant->id, 'name' => $tenant->name, 'tenant_type' => $tenant->tenant_type] + $this->billing->status($tenant->id))->values());
    }

    public function assign(Request $request, int $tenant): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['package', 'unlimited', 'none'])],
            'pk_package_id' => ['required_if:mode,package', 'nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->billing->assign($tenant, $data['mode'], $data['pk_package_id'] ?? null, $request->user()->id, $data['note'] ?? null);

        return response()->json($this->billing->status($tenant));
    }

    public function credit(Request $request, int $tenant): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'note' => ['required', 'string', 'max:255'],
        ]);
        $this->billing->addManual($tenant, (int) $data['amount'], $request->user()->id, $data['note']);

        return response()->json($this->billing->status($tenant));
    }

    public function entries(Request $request, int $tenant): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->billing->entries($tenant, (int) ($data['page'] ?? 1)));
    }

    private function validatePackage(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'account_type' => ['required', Rule::in(PkBillingService::ACCOUNT_TYPES)],
            'monthly_credits' => ['required', 'integer', 'min:0', 'max:1000000'],
            'equipment_limit' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'monthly_price' => ['nullable', 'numeric', 'min:0'],
            'yearly_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $data + ['equipment_limit' => null, 'monthly_price' => null, 'yearly_price' => null];
    }
}
