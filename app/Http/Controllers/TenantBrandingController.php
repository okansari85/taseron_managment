<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class TenantBrandingController extends Controller
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    /**
     * Herhangi bir rolden (contractor dahil) kimliği doğrulanmış kullanıcının
     * içinde bulunduğu tenant için görsel marka bilgisini döner. Sadece
     * gösterim amaçlı, hassas veri içermez — bu yüzden super-admin dışındaki
     * rollere de açık tutuldu.
     */
    public function show(): JsonResponse
    {
        $tenant = $this->tenantContext->get()->loadMissing('featuredBrand');

        return response()->json([
            'data' => [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'featured_brand' => $tenant->featuredBrand ? [
                    'id' => $tenant->featuredBrand->id,
                    'name' => $tenant->featuredBrand->name,
                    'logo_url' => $tenant->featuredBrand->logo_url,
                ] : null,
            ],
        ]);
    }
}
