<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $tenantId = $request->header('X-Tenant-ID');

        if ($tenantId) {
            $tenant = \App\Models\Tenant::findOrFail($tenantId);

            $this->tenantContext->set($tenant);
        }

        return $next($request);
    }
}
