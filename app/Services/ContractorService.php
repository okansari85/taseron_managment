<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\BusinessEntity;
use App\Models\Contractor;
use App\Repositories\Contracts\ContractorRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class ContractorService
{
    private const LOGO_DIRECTORY = 'contractors/logos';

    public function __construct(
        private ContractorRepositoryInterface $repository,
        private TenantContext $tenantContext,
        private ImageService $imageService
    ) {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): Contractor
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Contractor
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        $logoPath = null;

        try {
            return DB::transaction(function () use ($data, &$logoPath) {
                $businessEntity = BusinessEntity::query()->create([
                    'tenant_id' => $this->tenantContext->id(),
                    'type' => 'contractor',
                    'name' => $data['name'],
                ]);

                if (($data['logo'] ?? null) instanceof UploadedFile) {
                    $logoPath = $this->imageService->upload(
                        $data['logo'],
                        self::LOGO_DIRECTORY
                    );
                }

                return $this->repository->create([
                    'business_entity_id' => $businessEntity->id,
                    'contractor_type' => $data['contractor_type'],
                    'short_name' => $data['short_name'] ?? null,
                    'logo_path' => $logoPath,
                ]);
            });
        } catch (Throwable $e) {
            $this->imageService->delete($logoPath);
            throw $e;
        }
    }

    public function update(
        Contractor $contractor,
        array $data
    ): Contractor {
        $newLogoPath = null;
        $oldLogoPath = null;

        try {
            $updatedContractor = DB::transaction(function () use (
                $contractor,
                $data,
                &$newLogoPath,
                &$oldLogoPath
            ) {
                $contractor->load('businessEntity');

                if ($contractor->businessEntity === null) {
                    throw new LogicException(
                        'Contractor için Business Entity bulunamadı.'
                    );
                }

                if ($contractor->businessEntity->type !== 'contractor') {
                    throw new LogicException(
                        'Business Entity contractor tipinde değil.'
                    );
                }

                $oldLogoPath = $contractor->logo_path;

                if (($data['logo'] ?? null) instanceof UploadedFile) {
                    $newLogoPath = $this->imageService->upload(
                        $data['logo'],
                        self::LOGO_DIRECTORY
                    );
                }

                $contractor = $this->repository->update(
                    $contractor,
                    [
                        'contractor_type' => $data['contractor_type'],
                        'short_name' => $data['short_name'] ?? null,
                        'logo_path' => $newLogoPath ?? $oldLogoPath,
                    ]
                );

                $contractor->businessEntity->update([
                    'name' => $data['name'],
                ]);

                return $contractor->refresh();
            });
        } catch (Throwable $e) {
            $this->imageService->delete($newLogoPath);
            throw $e;
        }

        if ($newLogoPath && $oldLogoPath && $oldLogoPath !== $newLogoPath) {
            $this->imageService->delete($oldLogoPath);
        }

        return $updatedContractor;
    }

    public function delete(Contractor $contractor): void
    {
        DB::transaction(function () use ($contractor) {
            $businessEntityId = $contractor->business_entity_id;

            $this->repository->delete($contractor);

            if ($businessEntityId !== null) {
                BusinessEntity::query()
                    ->whereKey($businessEntityId)
                    ->delete();
            }
        });
    }
}
