<?php

namespace App\Repositories;

use App\Models\Location;
use App\Models\LocationExpert;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class LocationExpertRepository
{
    public function all(Location $location): Collection
    {
        return LocationExpert::query()
            ->with('user')
            ->where('location_id', $location->id)
            ->orderBy('id')
            ->get();
    }

    public function attach(Location $location, User $user): LocationExpert
    {
        return LocationExpert::query()->firstOrCreate([
            'location_id' => $location->id,
            'user_id' => $user->id,
        ]);
    }

    public function detach(Location $location, User $user): int
    {
        return LocationExpert::query()
            ->where('location_id', $location->id)
            ->where('user_id', $user->id)
            ->delete();
    }
}
