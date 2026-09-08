<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceThemeController extends Controller
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    /**
     * Kimliği doğrulanmış kullanıcının sidebar/header temasını döner.
     *
     * Kullanıcı belirli bir organizasyona (ör. bir gruba) scope'lanmışsa
     * o organizasyonun rengi + varsayılan marka logosu öncelikli olarak
     * döner; yoksa tenant'ın genel öne çıkan markası fallback olur.
     * Sadece gösterim amaçlı, hassas veri içermez.
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->get()->loadMissing('featuredBrand');

        $organizationScope = $request->user()
            ?->scopes()
            ->where('scope_type', 'organization')
            ->first();

        $organization = null;

        if ($organizationScope) {
            $organization = Organization::query()
                ->where('tenant_id', $tenant->id)
                ->with('defaultBrand')
                ->find($organizationScope->scope_id);
        }

        return response()->json([
            'data' => [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'featured_brand' => $tenant->featuredBrand ? [
                    'id' => $tenant->featuredBrand->id,
                    'name' => $tenant->featuredBrand->name,
                    'logo_url' => $tenant->featuredBrand->logo_url,
                ] : null,
                'organization' => $organization ? [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'type' => $organization->type,
                    'color' => $organization->color,
                    'default_brand' => $organization->defaultBrand ? [
                        'id' => $organization->defaultBrand->id,
                        'name' => $organization->defaultBrand->name,
                        'logo_url' => $organization->defaultBrand->logo_url,
                    ] : null,
                ] : null,
            ],
        ]);
    }
}
