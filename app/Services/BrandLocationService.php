<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Brand;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class BrandLocationService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function attach(
        Brand $brand,
        Location $location
    ): void {
        $this->ensureTenantContext();

        $this->ensureSameTenant(
            $brand,
            $location
        );

        DB::transaction(function () use (
            $brand,
            $location
        ) {
            if (
                $location->brands()
                    ->whereKey($brand->id)
                    ->exists()
            ) {
                return;
            }

            $existingBrand = $location->brands()->first();

            if ($existingBrand !== null) {
                throw new RuntimeException(
                    'Bu lokasyon zaten başka bir markaya bağlıdır.'
                );
            }

            $brand->locations()->attach(
                $location->id
            );
        });
    }

    public function detach(
        Brand $brand,
        Location $location
    ): void {
        $this->ensureTenantContext();

        $this->ensureSameTenant(
            $brand,
            $location
        );

        DB::transaction(function () use (
            $brand,
            $location
        ) {
            $brand->locations()->detach(
                $location->id
            );
        });
    }

    public function sync(
        Brand $brand,
        array $locationIds
    ): void {
        $this->ensureTenantContext();

        $locationIds = array_values(
            array_unique($locationIds)
        );

        $locations = Location::query()
            ->whereIn('id', $locationIds)
            ->get();

        if (
            $locations->count() !==
            count($locationIds)
        ) {
            throw new RuntimeException(
                'Seçilen lokasyon kayıtlarından biri veya birkaçı bulunamadı.'
            );
        }

        foreach ($locations as $location) {
            $this->ensureSameTenant(
                $brand,
                $location
            );
        }

        DB::transaction(function () use (
            $brand,
            $locationIds
        ) {
            $brand->locations()->sync(
                $locationIds
            );
        });
    }

    private function ensureTenantContext(): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }
    }

    private function ensureSameTenant(
        Brand $brand,
        Location $location
    ): void {
        $tenantId = $this->tenantContext->id();

        if ($brand->tenant_id !== $tenantId) {
            throw new RuntimeException(
                'Bu markaya erişim yetkiniz yok.'
            );
        }

        if ($location->tenant_id !== $tenantId) {
            throw new RuntimeException(
                'Bu lokasyona erişim yetkiniz yok.'
            );
        }
    }
}
