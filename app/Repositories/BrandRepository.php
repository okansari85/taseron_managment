<?php

namespace App\Repositories;

use App\Models\Brand;
use App\Repositories\Contracts\BrandRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class BrandRepository implements BrandRepositoryInterface
{
    public function all(): Collection
    {
        return Brand::query()
            ->with('companies.organizations')
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): Brand
    {
        return Brand::query()->findOrFail($id);
    }

    public function create(array $data): Brand
    {
        return Brand::query()->create($data);
    }

    public function update(
        Brand $brand,
        array $data
    ): Brand {
        $brand->update($data);

        return $brand->refresh();
    }

    public function delete(Brand $brand): void
    {
        $brand->delete();
    }
}
