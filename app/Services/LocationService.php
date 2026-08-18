<?php

namespace App\Services;

use App\Models\Location;
use App\Repositories\Contracts\LocationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LocationService
{
    public function __construct(
        private LocationRepositoryInterface $repository
    ) {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): Location
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Location
    {
        return DB::transaction(function () use ($data) {
            return $this->repository->create($data);
        });
    }

    public function update(
        Location $location,
        array $data
    ): Location {
        return DB::transaction(function () use ($location, $data) {
            return $this->repository->update(
                $location,
                $data
            );
        });
    }

    public function delete(Location $location): void
    {
        DB::transaction(function () use ($location) {
            $this->repository->delete($location);
        });
    }
}