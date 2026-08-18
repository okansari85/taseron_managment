<?php

namespace App\Repositories\Contracts;

use App\Models\Contractor;
use Illuminate\Database\Eloquent\Collection;

interface ContractorRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): Contractor;

    public function create(array $data): Contractor;

    public function update(
        Contractor $contractor,
        array $data
    ): Contractor;

    public function delete(Contractor $contractor): void;
}
