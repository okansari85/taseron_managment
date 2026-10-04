<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Services\PkBillingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

// pktakip: yeni aktif ekipmandan önce paketin ekipman limiti (ekipman kodu değişmez). Parametre: one (tek ekipman),
// ids (istekteki her satır bir ekipman), activate (istekteki pasif ekipmanlar aktife alınır).
class EnsurePkEquipmentLimit
{
    public function __construct(private PkBillingService $billing, private TenantContext $tenantContext)
    {
    }

    public function handle(Request $request, Closure $next, string $mode = 'one'): Response
    {
        if ($this->tenantContext->has()) {
            $tenantId = $this->tenantContext->id();
            $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
            $count = match ($mode) {
                'ids' => count($ids),
                'activate' => $ids ? DB::table('pk_equipment')->where('tenant_id', $tenantId)->whereIn('id', $ids)->where('is_active', false)->count() : 0,
                default => 1,
            };
            $this->billing->assertCanAddEquipment($tenantId, $count);
        }

        return $next($request);
    }
}
