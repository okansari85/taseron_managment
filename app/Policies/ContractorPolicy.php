<?php

namespace App\Policies;

use App\Models\Contractor;
use App\Models\User;

class ContractorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super-admin') || $user->hasRole('contractor');
    }

    public function view(User $user, Contractor $contractor): bool
    {
        return $user->hasRole('super-admin') || $user->contractor_id === $contractor->id;
    }

    public function update(User $user, Contractor $contractor): bool
    {
        return $user->hasRole('super-admin') || $user->contractor_id === $contractor->id;
    }

    public function delete(User $user, Contractor $contractor): bool
    {
        return $user->hasRole('super-admin');
    }
}
