<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerLocationService;
use App\Services\CustomerOrganizationService;
use App\Services\PkAccountService;
use App\Services\PkWorkplaceAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Üstteki lokasyon menüsünün Organizasyon kutusu (yalnızca OSGB / kurumsal hesabın yöneticisi): hesabın müşterileri, her
// müşterinin organizasyon ağacı ve lokasyonların bağlı olduğu düğüm. Yalnızca okur; mevcut servisler çağrılır.
class PkAccountOrganizationController extends Controller
{
    public function __invoke(Request $request, PkWorkplaceAssignmentService $assignments, PkAccountService $accounts, CustomerOrganizationService $organizations, CustomerLocationService $locations): JsonResponse
    {
        // Hesap ve yetki: yönetici / operasyon kontrolü (tenant bağlamını da kurar), ardından yalnızca yönetici.
        $tenant = $assignments->assignerTenant($request->user());
        abort_unless($accounts->roleOf($tenant, $request->user()) === 'yonetici', 403, 'Organizasyon seçimi yalnızca yöneticide var.');

        $customers = [];
        $locationNodes = [];
        foreach (Customer::query()->where('tenant_id', $tenant->id)->orderBy('name')->get() as $customer) {
            $customerLocations = $locations->list($customer);
            foreach ($customerLocations as $location) {
                if ($location['organization_id']) {
                    $locationNodes[$location['id']] ??= $location['organization_id'];
                }
            }
            $customers[] = [
                'id' => $customer->id,
                'name' => $customer->name,
                'nodes' => $this->nodes($organizations->tree($customer)),
                'location_ids' => $customerLocations->pluck('id')->values()->all(),
            ];
        }

        return response()->json(['customers' => $customers, 'location_nodes' => (object) $locationNodes]);
    }

    // Ağaç: yalnızca id, ad ve alt düğümler.
    private function nodes(array $tree): array
    {
        return array_map(fn (array $node) => [
            'id' => $node['id'],
            'name' => $node['name'],
            'children' => $this->nodes($node['children'] ?? []),
        ], $tree);
    }
}
