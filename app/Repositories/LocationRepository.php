<?php

namespace App\Repositories;

use App\Models\Location;
use App\Repositories\Contracts\LocationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class LocationRepository implements LocationRepositoryInterface
{
    private const RELATIONS = [
        'city:id,name',
        'district:id,city_id,name',
        'businessEntities:id,name,type',
        'businessEntities.company:id,name,business_entity_id',
        'businessEntities.company.brands:id,name',
    ];

    public function all(): Collection
    {
        $locations = Location::query()->with(self::RELATIONS)->orderBy('name')->get();
        $this->loadPivotBrands($locations);

        return $locations;
    }

    public function find(int $id): Location
    {
        $location = Location::query()->with(self::RELATIONS)->findOrFail($id);
        $this->loadPivotBrands(Collection::make([$location]));

        return $location;
    }

    public function create(array $data): Location
    {
        $location = Location::query()->create($data)->load(self::RELATIONS);
        $this->loadPivotBrands(Collection::make([$location]));

        return $location;
    }

    public function update(Location $location, array $data): Location
    {
        $location->update($data);
        $location = $location->refresh()->load(self::RELATIONS);
        $this->loadPivotBrands(Collection::make([$location]));

        return $location;
    }

    public function delete(Location $location): void { $location->delete(); }

    /**
     * Her lokasyonun businessEntities listesindeki pivot (LocationBusinessEntity) satırına
     * o şubeye ÖZEL atanmış markaları ('brands' many-to-many, location_business_entity_brands
     * üzerinden) yükler. 'businessEntities.company.brands' (yukarıdaki RELATIONS) şirketin
     * TÜM markalarını taşır — burada eklenen ise sadece o spesifik şube kaydına atanmış
     * tekil/çoklu markayı taşır, frontend'in lokasyon listesinde doğru marka rozetini
     * gösterebilmesi için gereken asıl veri budur.
     */
    private function loadPivotBrands(Collection $locations): void
    {
        $pivots = $locations->flatMap(
            fn (Location $location) => $location->businessEntities->map(fn ($entity) => $entity->pivot)
        )->filter();

        if ($pivots->isEmpty()) {
            return;
        }

        Collection::make($pivots->all())->loadMissing('brands:id,name,logo_path');
    }
}
