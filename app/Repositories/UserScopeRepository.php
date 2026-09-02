<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\UserScope;
use Illuminate\Database\Eloquent\Collection;

class UserScopeRepository
{
    public function all(User $user): Collection
    {
        return $user->scopes()->orderBy('scope_type')->orderBy('scope_id')->get();
    }

    public function attach(User $user, string $scopeType, int $scopeId): UserScope
    {
        return UserScope::query()->firstOrCreate([
            'user_id' => $user->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);
    }

    public function detach(User $user, string $scopeType, int $scopeId): int
    {
        return UserScope::query()
            ->where('user_id', $user->id)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->delete();
    }

    public function sync(User $user, array $scopes): void
    {
        $user->scopes()->delete();

        foreach ($scopes as $scope) {
            $this->attach($user, $scope['scope_type'], (int) $scope['scope_id']);
        }
    }
}
