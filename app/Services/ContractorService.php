<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\BusinessEntity;
use App\Models\Contractor;
use App\Repositories\Contracts\ContractorRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ContractorService
{
    public function __construct(
        private ContractorRepositoryInterface $repository,
        private TenantContext $tenantContext
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

        return DB::transaction(function () use ($data) {
            $businessEntity = BusinessEntity::query()->create([
                'tenant_id' => $this->tenantContext->id(),
                'type' => 'contractor',
                'name' => $data['name'],
            ]);

            return $this->repository->create([
                'business_entity_id' => $businessEntity->id,
                'contractor_type' => $data['contractor_type'],
            ]);
        });
    }

    public function update(
        Contractor $contractor,
        array $data
    ): Contractor {
        return DB::transaction(function () use ($contractor, $data) {
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

            $contractor = $this->repository->update(
                $contractor,
                [
                    'contractor_type' => $data['contractor_type'],
                ]
            );

            $contractor->businessEntity->update([
                'name' => $data['name'],
            ]);

            return $contractor->refresh();
        });
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
