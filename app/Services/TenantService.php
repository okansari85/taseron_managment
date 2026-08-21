<?php

namespace App\Services;

use App\Models\Tenant;
use App\Repositories\Contracts\TenantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TenantService
{
    public function __construct(
        private TenantRepositoryInterface $repository
    ) {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): Tenant
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            return $this->repository->create($data);
        });
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $oldLogoPath = $tenant->logo_path;
        $newLogoPath = null;

        try {
            $updatedTenant = DB::transaction(function () use ($tenant, $data, &$newLogoPath) {
                if (($data['logo'] ?? null) instanceof UploadedFile) {
                    $newLogoPath = $data['logo']->store('tenant-logos', 'public');
                    $data['logo_path'] = $newLogoPath;
                }

                unset($data['logo']);

                return $this->repository->update($tenant, $data);
            });

            if ($newLogoPath !== null && $oldLogoPath && $oldLogoPath !== $newLogoPath) {
                Storage::disk('public')->delete($oldLogoPath);
            }

            return $updatedTenant;
        } catch (Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }
    }

    public function delete(Tenant $tenant): void
    {
        $logoPath = $tenant->logo_path;

        DB::transaction(function () use ($tenant) {
            $this->repository->delete($tenant);
        });

        if ($logoPath) {
            Storage::disk('public')->delete($logoPath);
        }
    }
}
