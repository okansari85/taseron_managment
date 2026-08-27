<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Repositories\Contracts\GroupRepositoryInterface;

class GroupRepository implements GroupRepositoryInterface
{
    public function createGroup(array $data): Organization
    {
        return Organization::query()->forceCreate($data);
    }
}
