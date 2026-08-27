<?php

namespace App\Repositories\Contracts;

use App\Models\Organization;

interface GroupRepositoryInterface
{
    public function createGroup(array $data): Organization;
}
