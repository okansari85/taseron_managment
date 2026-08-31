<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Location;
use App\Repositories\Contracts\LocationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;

class LocationService
{
    public function __construct(
        private LocationRepositoryInterface $repository,
        private TenantContext $tenantContext,
        private LocationImageService $imageService
    ) {}

    public function all(): Collection { return $this->repository->all(); }
    public function find(int $id): Location { return $this->repository->find($id); }

    public function create(array $data): Location
    {
        if (! $this->tenantContext->has()) throw new LogicException('Tenant context has not been initialized.');
        $data['tenant_id'] = $this->tenantContext->id();
        $image = $data['image'] ?? null;
        unset($data['image']);
        $imagePath = null;

        try {
            if ($image instanceof UploadedFile) $imagePath = $this->imageService->upload($image);
            if ($imagePath) $data['image'] = $imagePath;
            return DB::transaction(fn () => $this->repository->create($data));
        } catch (\Throwable $e) {
            if ($imagePath) $this->imageService->delete($imagePath);
            throw $e;
        }
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
            if ($image instanceof UploadedFile) $newImagePath = $this->imageService->upload($image);
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
