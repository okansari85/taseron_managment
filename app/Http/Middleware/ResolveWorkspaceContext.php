<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\WorkspaceContext;
use App\Services\WorkspaceContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header'daki aktif Organizasyon/Marka + Lokasyon seçimini okuyup
 * WorkspaceContext'e yazar. X-Organization-Id gönderilmezse (süper admin
 * dahil çoğu istek) hiçbir şey yapmaz — mevcut davranış aynen korunur.
 *
 * Bu bir güvenlik middleware'i DEĞİL; erişim WorkspaceContextService
 * içindeki mevcut kontrollerle (kullanıcının erişilebilir ağacı) zaten
 * doğrulanıyor. Burada bir hata olursa istek reddedilmez, sadece context
 * boş bırakılır (fail-open) — çünkü bu sadece bir varsayılan/filtredir.
 */
class ResolveWorkspaceContext
{
    public function __construct(
        private WorkspaceContext $workspaceContext,
        private WorkspaceContextService $workspaceContextService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization-Id');
        $user = $request->user();

        if ($organizationId && $user) {
            $kind = $request->header('X-Organization-Kind', 'organization');
            $kind = in_array($kind, ['organization', 'brand'], true) ? $kind : 'organization';

            try {
                $result = $this->workspaceContextService->locationOptions($user, (int) $organizationId, $kind);
                $this->workspaceContext->setAllowedLocationIds(array_column($result['items'], 'id'));
                $this->workspaceContext->setAllowedBusinessEntityIds(
                    $this->workspaceContextService->businessEntityIds($user, (int) $organizationId, $kind)
                );
            } catch (ValidationException) {
                // Geçersiz/erişim dışı bir id — context'i boş bırak, isteği engelleme.
            }
        }

        $locationId = $request->header('X-Location-Id');

        if ($locationId) {
            $this->workspaceContext->setSelectedLocationId((int) $locationId);
        }

        return $next($request);
    }
}
