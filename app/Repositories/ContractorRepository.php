<?php

namespace App\Repositories;

use App\Models\Contractor;
use App\Repositories\Contracts\ContractorRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ContractorRepository implements ContractorRepositoryInterface
{
    public function all(): Collection
    {
        return Contractor::query()
            ->with('businessEntity')
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id): Contractor
    {
        return Contractor::query()
            ->with('businessEntity')
            ->findOrFail($id);
    }

    public function create(array $data): Contractor
    {
        return Contractor::query()->create($data);
    }

    public function update(
        Contractor $contractor,
        array $data
    ): Contractor {
        $contractor->update($data);

        return $contractor->refresh();
    }

    public function delete(Contractor $contractor): void
    {
        $contractor->delete();
    }
}
