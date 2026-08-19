<?php

namespace App\Repositories\Contracts;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Collection;

interface BrandRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): Brand;

    public function create(array $data): Brand;

    public function update(
        Brand $brand,
        array $data
    ): Brand;

    public function delete(Brand $brand): void;
}
