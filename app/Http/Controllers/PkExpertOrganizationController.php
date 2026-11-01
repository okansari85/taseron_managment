<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Services\CustomerLocationService;
use App\Services\CustomerOrganizationService;
use Illuminate\Http\JsonResponse;

// Üstteki menünün Organizasyon kutusu, bireysel uzman için: PkAccountOrganizationController ile aynı yanıt. O uç yalnızca OSGB /
// kurumsal yöneticiye açık; çok kullanıcılı hesabın uzmanı atanmadığı müşterileri görmesin diye bu uç yalnızca bireysel hesaba açık.
class PkExpertOrganizationController extends Controller
{
    public function __invoke(TenantContext $tenantContext, CustomerOrganizationService $organizations, CustomerLocationService $locations): JsonResponse
    {
        $tenant = $tenantContext->get();
        abort_unless($tenant->tenant_type === 'expert', 403, 'Bu liste yalnızca bireysel uzman hesabında var.');

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
