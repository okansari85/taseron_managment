<?php

namespace App\Services;

use App\Models\BusinessEntity;
use App\Models\Location;
use App\Repositories\Contracts\LocationBusinessEntityRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Illuminate\Validation\ValidationException;

class LocationBusinessEntityService
{
    public function __construct(
        private LocationBusinessEntityRepositoryInterface $repository
    ) {
    }

    public function all(Location $location): Collection
    {
        return $this->repository->all($location);
    }

    public function attach(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void {
        $this->validateBusinessEntity(
            $businessEntity,
            $pivotData
        );

        DB::transaction(function () use (
            $location,
            $businessEntity,
            $pivotData
        ) {
            $this->repository->attach(
                $location,
                $businessEntity,
                $pivotData
            );
        });
    }

    public function update(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void {
        $this->validateBusinessEntity(
            $businessEntity,
            $pivotData
        );

        DB::transaction(function () use (
            $location,
            $businessEntity,
            $pivotData
        ) {
            $this->repository->update(
                $location,
                $businessEntity,
                $pivotData
            );
        });
    }

    public function detach(
        Location $location,
        BusinessEntity $businessEntity
    ): void {
        DB::transaction(function () use (
            $location,
            $businessEntity
        ) {
            $this->repository->detach(
                $location,
                $businessEntity
            );
        });
    }

  private function validateBusinessEntity(
    BusinessEntity $businessEntity,
    array &$pivotData
): void {
    // Şirket lokasyona bağlanabilir.
    if ($businessEntity->type === 'company') {
        return;
    }

    // Sadece company ve contractor BusinessEntity
    // lokasyon ilişkisine aday olabilir.
    if ($businessEntity->type !== 'contractor') {
        throw new LogicException(
            'Bu Business Entity tipi lokasyona bağlanamaz.'
        );
    }

    $contractor = $businessEntity->contractor;

    if ($contractor === null) {
        throw new LogicException(
            'Business Entity için Contractor kaydı bulunamadı.'
        );
    }

    // Geçici taşeron lokasyona bağlanamaz.
    if ($contractor->contractor_type === 'temporary') {
    throw ValidationException::withMessages([
        'business_entity_id' => [
            'Geçici taşeron lokasyona bağlanamaz.'
        ],
    ]);
    }

    // Buraya gelen contractor artık permanent olmalıdır.
}
}
