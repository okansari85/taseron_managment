<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Services\PkBillingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// pktakip: yeni işyerinden (location_business_entity) önce paketin işyeri limiti (firma ekleme kodu değişmez). pktakip dışındaki
// hesaplarda (Taşeron) aboneliği olmadığı için hiçbir şey yapmaz.
class EnsurePkWorkplaceLimit
{
    public function __construct(private PkBillingService $billing, private TenantContext $tenantContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenantContext->has()) {
            $this->billing->assertCanAddWorkplace($this->tenantContext->id());
        }

        return $next($request);
    }
}
