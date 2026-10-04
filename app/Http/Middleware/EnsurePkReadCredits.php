<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Services\PkBillingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// pktakip: yapay zeka ile rapor okumadan önce kredi kontrolü (okuma kodu değişmez). Test verisiyle (fixture_id) okuma yapay
// zekayı çağırmaz, kredi düşmez: kontrol edilmez. Parametre: equipment | installation.
class EnsurePkReadCredits
{
    public function __construct(private PkBillingService $billing, private TenantContext $tenantContext)
    {
    }

    public function handle(Request $request, Closure $next, string $kind = 'equipment'): Response
    {
        if ($this->tenantContext->has() && !$request->filled('fixture_id')) {
            $this->billing->assertCanRead($this->tenantContext->id(), $kind);
        }

        return $next($request);
    }
}
