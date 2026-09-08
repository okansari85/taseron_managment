<?php

namespace App\Repositories;

use App\Models\LocationBusinessEntity;
use App\Models\LocationExpert;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class LocationExpertRepository
{
    public function all(LocationBusinessEntity $locationBusinessEntity): Collection
    {
        return LocationExpert::query()
            ->with('user')
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->orderBy('id')
            ->get();
    }

    public function forUser(User $user, int $tenantId): Collection
    {
        return LocationExpert::query()
            ->with(['locationBusinessEntity.location', 'locationBusinessEntity.businessEntity'])
            ->where('user_id', $user->id)
            ->whereHas('locationBusinessEntity.location', fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('id')
            ->get();
    }

    public function attach(LocationBusinessEntity $locationBusinessEntity, User $user): LocationExpert
    {
        return LocationExpert::query()->firstOrCreate([
            'location_business_entity_id' => $locationBusinessEntity->id,
            'user_id' => $user->id,
        ]);
    }

    public function detach(LocationBusinessEntity $locationBusinessEntity, User $user): int
    {
        return LocationExpert::query()
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->where('user_id', $user->id)
            ->delete();
    }
}
