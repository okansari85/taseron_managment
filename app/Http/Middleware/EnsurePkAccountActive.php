<?php

namespace App\Http\Middleware;

use App\Services\PkAccountService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * pktakip: süper adminin pasife aldığı hesabın (tenants.status = false) kullanıcıları API'yi kullanamaz; veriler kalır, aktife
 * alınınca kaldığı yerden devam eder (kullanıcı 19 Ekim 2026). Yalnız pktakip hesap türleri (uzman, OSGB, kurumsal, kamu);
 * Taşeron firmaları etkilenmez. Süper admin ve süper adminin "hesaba gir" oturumu (impersonation jetonu) engellenmez.
 */
class EnsurePkAccountActive
{
    public function __construct(private PkAccountService $accounts)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');
        if (!$user || $user->hasRole('super-admin') || $user->currentAccessToken()?->name === 'impersonation') {
            return $next($request);
        }

        $tenant = $this->accounts->tenantOf($user);
        if ($tenant && in_array($tenant->tenant_type, PkAccountService::TYPES, true) && !$tenant->status) {
            return response()->json([
                'message' => 'Hesabınız pasife alınmıştır. Ayrıntı için bizimle iletişime geçin.',
                'code' => 'hesap_pasif',
            ], 403);
        }

        return $next($request);
    }
}
