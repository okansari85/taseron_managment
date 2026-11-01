<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Organization;
use App\Services\CustomerOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * pktakip: firma sayfasında müşteri ağacındaki tek bir organizasyon düğümünü siler.
 *
 * Mevcut organizasyon silme (OrganizationService::delete) değişmez; o uç "company" / "brand" türündeki düğümü reddeder ve
 * kural ihlalinde 500 döner. Bu uç türe bakmaz (firma sayfasının önceki sürümünde "company" türüyle açılmış boş düğüm de silinir)
 * ve her engeli okunur bir mesajla (422) bildirir. Önyüz önce lokasyonları, sonra alttaki düğümleri siler; burada düğümün altı ve
 * lokasyon bağı boş olmalıdır. Firmanın kök düğümü, başka bir müşteriye bağlı düğüm ve şirket / taşeron / iş talebi / marka
 * kaydına bağlı düğüm silinmez (müşteri silmedeki engellerle aynı: CustomerPurgeService).
 */
class PkOrganizationNodeController extends Controller
{
    public function __construct(
        private CustomerOrganizationService $customerOrganizationService,
        private TenantContext $tenantContext
    ) {
    }

    public function destroy(Customer $customer, Organization $organization): JsonResponse
    {
        $id = (int) $organization->id;

        // tree() müşterinin tenant kontrolünü de yapar.
        if (! $this->organizationIdsInTree($this->customerOrganizationService->tree($customer))->contains($id)) {
            $this->fail('Seçilen organizasyon bu müşteriye bağlı değil.');
        }

        $tenantId = $this->tenantContext->id();

        if (DB::table('customer_organization')->where('customer_id', $customer->id)->where('organization_id', $id)->exists()) {
            $this->fail('Firmanın en üstteki organizasyonu silinemez. Firmayı silmek için "Diğer işlemler → Firmayı sil" kullanın.');
        }

        if (DB::table('organizations')->where('tenant_id', $tenantId)->where('parent_id', $id)->exists()) {
            $this->fail('Bu organizasyonun altında başka organizasyonlar var. Önce onları silin.');
        }

        if ($count = DB::table('organization_locations')->where('organization_id', $id)->count()) {
            $this->fail("Bu organizasyona bağlı {$count} lokasyon var. Önce lokasyonları silin.");
        }

        $blockers = [];
        if (DB::table('customer_organization')->where('organization_id', $id)->where('customer_id', '!=', $customer->id)->exists()) {
            $blockers[] = 'başka bir müşteriye de bağlı';
        }
        if ($count = DB::table('organization_companies')->where('organization_id', $id)->count()) {
            $blockers[] = "{$count} organizasyon-firma bağlantısı";
        }
        if ($count = DB::table('organization_contractors')->where('organization_id', $id)->count()) {
            $blockers[] = "{$count} taşeron bağlantısı";
        }
        if (Schema::hasTable('work_requests') && ($count = DB::table('work_requests')->where('organization_id', $id)->count())) {
            $blockers[] = "{$count} iş talebi";
        }
        if (Schema::hasColumn('brands', 'organization_id') && ($count = DB::table('brands')->where('organization_id', $id)->count())) {
            $blockers[] = "{$count} marka kaydı";
        }
        if ($blockers) {
            $this->fail('Bu organizasyon silinemez: '.implode(', ', $blockers).'.');
        }

        DB::table('organizations')->where('tenant_id', $tenantId)->where('id', $id)->delete();

        return response()->json(['message' => 'Organizasyon silindi.']);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['organization' => $message]);
    }

    private function organizationIdsInTree(array $nodes): Collection
    {
        $ids = collect();
        $walk = function (array $nodes) use (&$walk, $ids): void {
            foreach ($nodes as $node) {
                $ids->push((int) $node['id']);
                $walk($node['children'] ?? []);
            }
        };
        $walk($nodes);

        return $ids;
    }
}
