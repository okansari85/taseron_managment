<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Services\PkAccountService;
use App\Services\PkBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// pktakip "Paketim" (kalan kredi, ekipman doluluğu) ve Analiz geçmişi → Kredi hareketleri. OSGB / kurumsal hesapta uzman
// yalnızca kendi okumalarının hareketlerini görür; yönetici, operasyon yöneticisi ve bireysel uzman hepsini.
class PkBillingController extends Controller
{
    public function __construct(private PkBillingService $billing, private TenantContext $tenantContext, private PkAccountService $accounts)
    {
    }

    public function status(): JsonResponse
    {
        return response()->json($this->billing->status($this->tenantContext->id()));
    }

    public function entries(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();
        $tenant = $this->accounts->tenantOf($user);
        $ownOnly = $tenant && in_array($tenant->tenant_type, PkAccountService::MULTI_USER_TYPES, true)
            && !in_array($this->accounts->roleOf($tenant, $user), ['yonetici', 'operasyon'], true);

        return response()->json($this->billing->entries($this->tenantContext->id(), (int) ($data['page'] ?? 1), $ownOnly ? $user->id : null));
    }
}
