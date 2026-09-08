<?php

namespace App\Services;

use App\Domain\Tenancy\WorkspaceContext;
use App\Models\BusinessEntity;
use App\Models\Location;
use App\Models\LocationBusinessEntity;
use App\Models\OperationalRegion;
use App\Models\Organization;
use App\Repositories\Contracts\LocationBusinessEntityRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Illuminate\Validation\ValidationException;
use Throwable;

class LocationBusinessEntityService
{
    public function __construct(
        private LocationBusinessEntityRepositoryInterface $repository,
        private OrganizationLocationService $organizationLocationService,
        private WorkspaceContext $workspaceContext,
    ) {
    }

    public function all(Location $location): Collection
    {
        $businessEntities = $this->repository->all($location);

        $businessEntities->each(function (BusinessEntity $businessEntity): void {
            if ($businessEntity->pivot instanceof Pivot) {
                $businessEntity->pivot->load(['brands', 'operationalRegion', 'photos']);
                $businessEntity->pivot->loadCount(['emergencyEquipment as equipment_count' => fn ($query) => $query->where('is_active', true)]);
            }
        });

        return $businessEntities;
    }

    // Header'daki aktif Organizasyon/Marka seçimine göre daraltır. Context
    // yoksa (header gönderilmediyse — süper admin dahil) davranış eskisiyle
    // aynıdır. allowedBusinessEntityIds() SATIR bazında filtreler (lokasyon
    // bazında değil) — aynı lokasyonda başka bir markanın şubesi varsa o
    // satır dışarıda kalır, sadece seçili markaya/şirkete ait şubeler döner.
    public function forTenant(int $tenantId): Collection
    {
        $businessEntities = $this->repository->forTenant($tenantId);

        $allowedIds = $this->workspaceContext->allowedBusinessEntityIds();

        if ($allowedIds !== null) {
            $businessEntities = $businessEntities->whereIn('id', $allowedIds)->values();
        }

        return $businessEntities;
    }

    public function attach(
        Location $location,
        BusinessEntity $businessEntity,
        array $pivotData
    ): void {
        $brandIds = $pivotData['brand_ids'] ?? [];
        unset($pivotData['brand_ids']);

        $photos = $pivotData['photos'] ?? [];
        unset($pivotData['photos']);

        $this->validateBusinessEntity($businessEntity, $pivotData, $location);
        $this->validateBrands($businessEntity, $brandIds);
        $this->assertNotAlreadyAttachedToRegion($location, $businessEntity, $pivotData['operational_region_id'] ?? null);

        $storedPhotoPaths = [];

        try {
            DB::transaction(function () use (
                $location,
                $businessEntity,
                $pivotData,
                $brandIds,
                $photos,
                &$storedPhotoPaths
            ) {
                $pivot = $this->repository->attach($location, $businessEntity, $pivotData);
                $this->syncBrands($pivot, $brandIds);
                $this->syncOrganizationLocation($location, $businessEntity, $brandIds);
                $storedPhotoPaths = $this->addPhotos($pivot, $photos);
            });
        } catch (Throwable $exception) {
            foreach ($storedPhotoPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
    }

    public function update(
        Location $location,
        LocationBusinessEntity $locationBusinessEntity,
        array $pivotData
    ): void {
        $this->assertBelongsToLocation($location, $locationBusinessEntity);
        $businessEntity = $locationBusinessEntity->businessEntity;
        $brandIds = $pivotData['brand_ids'] ?? null;
        unset($pivotData['brand_ids']);

        $photos = $pivotData['photos'] ?? [];
        $removePhotoIds = $pivotData['remove_photo_ids'] ?? [];
        unset($pivotData['photos'], $pivotData['remove_photo_ids']);

        $this->validateBusinessEntity($businessEntity, $pivotData, $location);

        if ($brandIds !== null) {
            $this->validateBrands($businessEntity, $brandIds);
        }

        $this->assertNotAlreadyAttachedToRegion(
            $location,
            $businessEntity,
            $pivotData['operational_region_id'] ?? null,
            $locationBusinessEntity->id
        );

        $removedPhotoPaths = [];
        $storedPhotoPaths = [];

        try {
            DB::transaction(function () use (
                $locationBusinessEntity,
                $pivotData,
                $brandIds,
                $photos,
                $removePhotoIds,
                &$removedPhotoPaths,
                &$storedPhotoPaths
            ) {
                $this->repository->update($locationBusinessEntity, $pivotData);

                if ($brandIds !== null) {
                    $this->syncBrands($locationBusinessEntity, $brandIds);
                }

                $removedPhotoPaths = $this->removePhotos($locationBusinessEntity, $removePhotoIds);
                $storedPhotoPaths = $this->addPhotos($locationBusinessEntity, $photos);
            });

            foreach ($removedPhotoPaths as $path) {
                Storage::disk('public')->delete($path);
            }
        } catch (Throwable $exception) {
            foreach ($storedPhotoPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
    }

    private function addPhotos(LocationBusinessEntity $pivot, array $photos): array
    {
        $photos = array_values(array_filter($photos, fn ($photo) => $photo instanceof UploadedFile));

        if ($photos === []) {
            return [];
        }

        $nextOrder = ((int) DB::table('location_business_entity_photos')
            ->where('location_business_entity_id', $pivot->id)
            ->max('order_no')) + 1;

        $storedPaths = [];
        $rows = [];
        $now = now();

        foreach ($photos as $photo) {
            $path = $photo->store('branch-photos', 'public');
            $storedPaths[] = $path;
            $rows[] = [
                'location_business_entity_id' => $pivot->id,
                'photo_path' => $path,
                'order_no' => $nextOrder++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('location_business_entity_photos')->insert($rows);

        return $storedPaths;
    }

    private function removePhotos(LocationBusinessEntity $pivot, array $photoIds): array
    {
        $photoIds = array_values(array_filter(array_map('intval', $photoIds)));

        if ($photoIds === []) {
            return [];
        }

        $photos = DB::table('location_business_entity_photos')
            ->where('location_business_entity_id', $pivot->id)
            ->whereIn('id', $photoIds)
            ->get(['id', 'photo_path']);

        DB::table('location_business_entity_photos')
            ->where('location_business_entity_id', $pivot->id)
            ->whereIn('id', $photoIds)
            ->delete();

        return $photos->pluck('photo_path')->all();
    }

    public function detach(Location $location, LocationBusinessEntity $locationBusinessEntity): void
    {
        $this->assertBelongsToLocation($location, $locationBusinessEntity);

        $photoPaths = DB::table('location_business_entity_photos')
            ->where('location_business_entity_id', $locationBusinessEntity->id)
            ->pluck('photo_path');

        DB::transaction(function () use ($locationBusinessEntity) {
            $this->repository->detach($locationBusinessEntity);
        });

        foreach ($photoPaths as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function assertBelongsToLocation(Location $location, LocationBusinessEntity $locationBusinessEntity): void
    {
        if ($locationBusinessEntity->location_id !== $location->id) {
            throw new LogicException(
                'Bu kayıt seçilen lokasyona ait değil.'
            );
        }
    }

    // Prevents attaching the same company/contractor to the same operational area
    // (or twice with no area at all) twice under the same location — each area
    // represents one real storefront/instance, so a duplicate here is always a
    // mistake, not a legitimate second attachment. $excludePivotId lets update()
    // skip the row being edited so it doesn't flag itself as a duplicate.
    private function assertNotAlreadyAttachedToRegion(
        Location $location,
        BusinessEntity $businessEntity,
        ?int $operationalRegionId,
        ?int $excludePivotId = null
    ): void {
        $query = DB::table('location_business_entities')
            ->where('location_id', $location->id)
            ->where('business_entity_id', $businessEntity->id);

        if ($operationalRegionId === null) {
            $query->whereNull('operational_region_id');
        } else {
            $query->where('operational_region_id', $operationalRegionId);
        }

        if ($excludePivotId !== null) {
            $query->where('id', '!=', $excludePivotId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'business_entity_id' => ['Bu firma, seçilen operasyonel alana zaten bağlı.'],
            ]);
        }
    }

    private function validateBusinessEntity(
        BusinessEntity $businessEntity,
        array &$pivotData,
        Location $location
    ): void {
        $operationalRegionId = $pivotData['operational_region_id'] ?? null;

        if ($operationalRegionId !== null) {
            $operationalRegion = OperationalRegion::query()
                ->whereKey($operationalRegionId)
                ->first();

            if ($operationalRegion === null) {
                throw ValidationException::withMessages([
                    'operational_region_id' => ['Seçilen operasyonel alan bulunamadı.'],
                ]);
            }

            if ($operationalRegion->location_id !== $location->id) {
                throw ValidationException::withMessages([
                    'operational_region_id' => [
                        'Seçilen operasyonel alan bu lokasyona ait değil.',
                    ],
                ]);
            }
        }

        if ($businessEntity->type === 'company') {
            return;
        }

        if ($businessEntity->type !== 'contractor') {
            throw new LogicException(
                'Bu Business Entity tipi lokasyona bağlanamaz.'
            );
        }

        $contractor = $businessEntity->contractor;

        if ($contractor === null) {
            throw new LogicException(
                'Business Entity için Contractor kaydı bulunamadı.'
            );
        }

        if ($contractor->contractor_type === 'temporary') {
            throw ValidationException::withMessages([
                'business_entity_id' => [
                    'Geçici taşeron lokasyona bağlanamaz.',
                ],
            ]);
        }
    }

    private function validateBrands(
        BusinessEntity $businessEntity,
        array $brandIds
    ): void {
        if ($brandIds === []) {
            return;
        }

        if ($businessEntity->type !== 'company' || $businessEntity->company === null) {
            throw ValidationException::withMessages([
                'brand_ids' => [
                    'Marka yalnızca şirket tipindeki Business Entity için tanımlanabilir.',
                ],
            ]);
        }

        $companyBrandCount = DB::table('company_brands')
            ->where('company_id', $businessEntity->company->id)
            ->whereIn('brand_id', array_values(array_unique($brandIds)))
            ->count();

        if ($companyBrandCount !== count(array_unique($brandIds))) {
            throw ValidationException::withMessages([
                'brand_ids' => [
                    'Seçilen markalardan biri bu şirkete bağlı değil.',
                ],
            ]);
        }
    }

    private function syncBrands(
        LocationBusinessEntity $pivot,
        array $brandIds
    ): void {
        DB::table('location_business_entity_brands')
            ->where('location_business_entity_id', $pivot->id)
            ->delete();

        if ($brandIds === []) {
            return;
        }

        $rows = [];
        $now = now();

        foreach (array_values(array_unique($brandIds)) as $brandId) {
            $rows[] = [
                'location_business_entity_id' => $pivot->id,
                'brand_id' => $brandId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('location_business_entity_brands')->insert($rows);
    }

    // Keeps the location's organization_locations link in sync with whichever
    // company/contractor node it now operates under, without the user having to
    // pick an organization separately (same node lookup OrganizationCompanyService
    // and OrganizationContractorService already maintain for that business entity).
    // When specific brands were selected for this attachment, the location is also
    // linked to each brand's own node so a brand-scoped user (e.g. a Burger King
    // manager under the same company as Popeyes) only sees their own locations.
    private function syncOrganizationLocation(Location $location, BusinessEntity $businessEntity, array $brandIds = []): void
    {
        $organizationId = match ($businessEntity->type) {
            'company' => $businessEntity->company
                ? DB::table('organization_companies')
                    ->where('company_id', $businessEntity->company->id)
                    ->value('company_node_id')
                : null,
            'contractor' => $businessEntity->contractor
                ? DB::table('organization_contractors')
                    ->where('contractor_id', $businessEntity->contractor->id)
                    ->orderBy('id')
                    ->value('organization_id')
                : null,
            default => null,
        };

        if ($organizationId !== null) {
            $organization = Organization::query()->find($organizationId);

            if ($organization !== null) {
                $this->organizationLocationService->attach($organization, $location);
            }
        }

        if ($brandIds === [] || $businessEntity->type !== 'company' || $businessEntity->company === null) {
            return;
        }

        $brandNodeIds = DB::table('company_brands')
            ->where('company_id', $businessEntity->company->id)
            ->whereIn('brand_id', array_values(array_unique($brandIds)))
            ->whereNotNull('brand_node_id')
            ->pluck('brand_node_id');

        foreach ($brandNodeIds as $brandNodeId) {
            $brandNode = Organization::query()->find($brandNodeId);

            if ($brandNode !== null) {
                $this->organizationLocationService->attach($brandNode, $location);
            }
        }
    }
}
