<?php

namespace App\Services;

use App\Models\Location;
use App\Models\OperationalUnit;
use App\Repositories\Contracts\OperationalUnitRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class OperationalUnitService
{
    public function __construct(
        private OperationalUnitRepositoryInterface $repository
    ) {
    }

    public function all(Location $location): Collection
    {
        return $this->repository->all($location);
    }

    public function find(
        Location $location,
        int $id
    ): OperationalUnit {
        return $this->repository->find($location, $id);
    }

    public function create(
        Location $location,
        array $data
    ): OperationalUnit {
        $this->ensureTenant($location);

        return DB::transaction(function () use ($location, $data) {
            return $this->repository->create($location, $data);
        });
    }

    public function update(
        Location $location,
        OperationalUnit $operationalUnit,
        array $data
    ): OperationalUnit {
        $this->ensureTenant($location);
        $this->ensureBelongsToLocation($location, $operationalUnit);

        return DB::transaction(function () use ($operationalUnit, $data) {
            return $this->repository->update(
                $operationalUnit,
                $data
            );
        });
    }

    public function delete(
        Location $location,
        OperationalUnit $operationalUnit
    ): void {
        $this->ensureTenant($location);
        $this->ensureBelongsToLocation($location, $operationalUnit);

        DB::transaction(function () use ($operationalUnit) {
            $this->repository->delete($operationalUnit);
        });
    }

    private function ensureTenant(Location $location): void
    {
        if (! app(\App\Domain\Tenancy\TenantContext::class)->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        if (
            $location->tenant_id !==
            app(\App\Domain\Tenancy\TenantContext::class)->id()
        ) {
            throw new RuntimeException(
                'Bu lokasyona erişim yetkiniz yok.'
            );
        }
    }

    private function ensureBelongsToLocation(
        Location $location,
        OperationalUnit $operationalUnit
    ): void {
        if ($operationalUnit->location_id !== $location->id) {
            throw new RuntimeException(
                'Operasyonel birim bu lokasyona ait değil.'
            );
        }
    }
}
