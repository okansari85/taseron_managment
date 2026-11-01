<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Services\CustomerOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * pktakip: firma sayfasında organizasyon düğümünün isteğe bağlı etiketi (bireysel uzman). Yalnız gösterim içindir; yalnızca
 * organizations.pk_label okunur / yazılır, mevcut uçlara ve organizations.type alanına dokunulmaz.
 */
class PkOrganizationLabelController extends Controller
{
    public const LABELS = ['Holding', 'Grup', 'Şirket', 'Marka', 'Bölge'];

    public function __construct(private CustomerOrganizationService $organizations, private TenantContext $tenantContext)
    {
    }

    // Müşterinin ağacındaki etiketler: düğüm kimliği → etiket (etiketsiz düğüm gelmez).
    public function index(Customer $customer): JsonResponse
    {
        $labels = DB::table('organizations')->whereIn('id', $this->treeIds($customer))->whereNotNull('pk_label')->pluck('pk_label', 'id');

        return response()->json((object) $labels->all());
    }

    public function update(Request $request, Customer $customer, int $organization): JsonResponse
    {
        $data = $request->validate(['label' => ['nullable', 'string', Rule::in(self::LABELS)]]);
        abort_unless($this->treeIds($customer)->contains($organization), 404, 'Organizasyon bu müşteriye bağlı değil.');

        DB::table('organizations')->where('id', $organization)->update(['pk_label' => $data['label'] ?? null, 'updated_at' => now()]);

        return response()->json(['id' => $organization, 'label' => $data['label'] ?? null]);
    }

    // tree() müşterinin hesaba ait olduğunu da denetler.
    private function treeIds(Customer $customer): Collection
    {
        abort_unless($this->tenantContext->get()->tenant_type === 'expert', 403, 'Etiket yalnızca bireysel uzman hesabında var.');

        $ids = collect();
        $walk = function (array $nodes) use (&$walk, $ids): void {
            foreach ($nodes as $node) {
                $ids->push((int) $node['id']);
                $walk($node['children'] ?? []);
            }
        };
        $walk($this->organizations->tree($customer));

        return $ids;
    }
}
