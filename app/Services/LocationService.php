<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\WorkspaceContext;
use App\Models\Location;
use App\Models\Organization;
use App\Models\UserScope;
use App\Repositories\Contracts\LocationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;

class LocationService
{
    private const IMAGE_DIRECTORY = 'location-images';

    public function __construct(
        private LocationRepositoryInterface $repository,
        private TenantContext $tenantContext,
        private WorkspaceContext $workspaceContext,
        private ImageService $imageService,
        private OrganizationService $organizationService,
        private OrganizationLocationService $organizationLocationService,
    ) {}

    // Header'daki aktif Organizasyon/Lokasyon seçimine göre listeyi daraltır.
    // WorkspaceContext boşsa (context header'ı gönderilmediyse — süper admin
    // dahil çoğu çağrı) davranış tamamen eskisiyle aynıdır. Sadece bu liste
    // metodu etkilenir; find/update/delete ve diğer servislerin Location'a
    // iç referansları bundan etkilenmez (bkz. WorkspaceContext docblock'u).
    public function all(): Collection
    {
        $locations = $this->repository->all();

        if ($this->workspaceContext->hasOrganizationFilter()) {
            $allowedIds = $this->workspaceContext->allowedLocationIds();
            $locations = $locations->whereIn('id', $allowedIds)->values();
        }

        if ($this->workspaceContext->selectedLocationId() !== null) {
            $locations = $locations->where('id', $this->workspaceContext->selectedLocationId())->values();
        }

        $this->attachCounts($locations);

        return $locations;
    }

    // İSG masaüstü Lokasyon Seç ekranındaki "X şube / Y ekipman" rozetleri için —
    // her Location'a şube (company tipi LBE) ve aktif ekipman sayısını iki toplu
    // sorguyla ekler (N+1'den kaçınmak için tek tek ilişki saymak yerine).
    private function attachCounts(Collection $locations): void
    {
        if ($locations->isEmpty()) {
            return;
        }

        $locationIds = $locations->pluck('id');

        $branchCounts = DB::table('location_business_entities')
            ->join('business_entities', 'business_entities.id', '=', 'location_business_entities.business_entity_id')
            ->whereIn('location_business_entities.location_id', $locationIds)
            ->where('business_entities.type', 'company')
            ->select('location_business_entities.location_id', DB::raw('count(*) as aggregate'))
            ->groupBy('location_business_entities.location_id')
            ->pluck('aggregate', 'location_id');

        $equipmentCounts = DB::table('location_emergency_equipment')
            ->join('location_business_entities', 'location_business_entities.id', '=', 'location_emergency_equipment.location_business_entity_id')
            ->whereIn('location_business_entities.location_id', $locationIds)
            ->where('location_emergency_equipment.is_active', true)
            ->select('location_business_entities.location_id', DB::raw('count(*) as aggregate'))
            ->groupBy('location_business_entities.location_id')
            ->pluck('aggregate', 'location_id');

        $locations->each(function (Location $location) use ($branchCounts, $equipmentCounts): void {
            $location->setAttribute('branch_count', (int) ($branchCounts[$location->id] ?? 0));
            $location->setAttribute('equipment_count', (int) ($equipmentCounts[$location->id] ?? 0));
        });
    }

    // "Yeni Şube" akışındaki "mevcut bina" seçici için: aktif context'ten
    // BAĞIMSIZ olarak tenant'taki tüm çok-şubeli binaları döner (müstakil
    // lokasyonlar hariç — onlara ikinci bir şube eklenmesi anlamsız).
    public function multiBranchBuildings(): Collection
    {
        return Location::query()
            ->where('allows_multiple_branches', true)
            ->with(['city:id,name', 'district:id,name'])
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): Location { return $this->repository->find($id); }

    public function create(array $data): Location
    {
        if (! $this->tenantContext->has()) throw new LogicException('Tenant context has not been initialized.');
        $data['tenant_id'] = $this->tenantContext->id();
        $image = $data['image'] ?? null;
        unset($data['image']);
        $imagePath = null;

        try {
            if ($image instanceof UploadedFile) $imagePath = $this->imageService->upload($image, self::IMAGE_DIRECTORY);
            if ($imagePath) $data['image'] = $imagePath;
            return DB::transaction(function () use ($data) {
                $location = $this->repository->create($data);
                $this->attachToScopedOrganization($location);
                return $location;
            });
        } catch (\Throwable $e) {
            if ($imagePath) $this->imageService->delete($imagePath);
            throw $e;
        }
    }

    // Locations must always land under some organization node so a group/company
    // manager's own scope doesn't leave them orphaned. Location itself is never an
    // Organization node (see 2026_08_28_220000_remove_location_type_from_organizations) -
    // this attaches directly to whichever node the creating user is scoped to, falling
    // back to the tenant root when they have no organization scope.
    private function attachToScopedOrganization(Location $location): void
    {
        $user = auth()->user();

        $organizationId = $user
            ? UserScope::query()
                ->where('user_id', $user->id)
                ->where('scope_type', 'organization')
                ->orderBy('id')
                ->value('scope_id')
            : null;

        $organization = $organizationId
            ? Organization::query()->where('tenant_id', $location->tenant_id)->find($organizationId)
            : null;

        $organization ??= $this->organizationService->getRootByTenantId($location->tenant_id);

        if ($organization === null) {
            return;
        }

        $this->organizationLocationService->attach($organization, $location);
    }

    public function update(Location $location, array $data): Location
    {
        if (! $this->tenantContext->has()) throw new LogicException('Tenant context has not been initialized.');
        if ($location->tenant_id !== $this->tenantContext->id()) throw new LogicException('Location mevcut tenant içerisinde değildir.');
        unset($data['tenant_id']);

        $image = $data['image'] ?? null;
        unset($data['image']);
        $newImagePath = null;
        $oldImagePath = $location->image;

        try {
            if ($image instanceof UploadedFile) $newImagePath = $this->imageService->upload($image, self::IMAGE_DIRECTORY);
            if ($newImagePath) $data['image'] = $newImagePath;
            $updated = DB::transaction(fn () => $this->repository->update($location, $data));
            if ($newImagePath && $oldImagePath) $this->imageService->delete($oldImagePath);
            return $updated;
        } catch (\Throwable $e) {
            if ($newImagePath) $this->imageService->delete($newImagePath);
            throw $e;
        }
    }

    public function delete(Location $location): void
    {
        if (! $this->tenantContext->has()) throw new LogicException('Tenant context has not been initialized.');
        if ($location->tenant_id !== $this->tenantContext->id()) throw new LogicException('Location mevcut tenant içerisinde değildir.');
        DB::transaction(fn () => $this->repository->delete($location));
    }
}
