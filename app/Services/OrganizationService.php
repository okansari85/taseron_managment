<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class OrganizationService
{
    public function __construct(
        private OrganizationRepositoryInterface $repository,
        private TenantContext $tenantContext
    ) {
    }

    public function all(): Collection
    {
        return $this->repository->all();
    }

    public function find(int $id): Organization
    {
        return $this->repository->find($id);
    }

    public function getRootByTenantId(int $tenantId): ?Organization
    {
        return $this->repository->getRootByTenantId($tenantId);
    }

    public function create(array $data): Organization
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        return DB::transaction(function () use ($data) {
            $tenantId = $this->tenantContext->id();

            $data['tenant_id'] = $tenantId;
            $data['parent_id'] ??= null;

            /*
             * Tenant içerisinde yalnızca bir root organization olabilir.
             * Root organization => parent_id = null
             */
            if ($data['parent_id'] === null) {
                $rootExists = Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('parent_id')
                    ->exists();

                if ($rootExists) {
                    throw new RuntimeException(
                        'Bu tenant için zaten bir kök organizasyon bulunmaktadır.'
                    );
                }
            } else {
                /*
                 * Parent mutlaka aynı tenant içerisinde bulunmalıdır.
                 */
                $parentExists = Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($data['parent_id'])
                    ->exists();

                if (! $parentExists) {
                    throw new RuntimeException(
                        'Seçilen üst organizasyon bu tenant içerisinde bulunamadı.'
                    );
                }
            }

            return $this->repository->create($data);
        });
    }

    public function update(
        Organization $organization,
        array $data
    ): Organization {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        return DB::transaction(function () use ($organization, $data) {
            $tenantId = $this->tenantContext->id();

            /*
             * Organization mevcut tenant'a ait olmalıdır.
             */
            if ($organization->tenant_id !== $tenantId) {
                throw new RuntimeException(
                    'Bu organizasyona erişim yetkiniz yok.'
                );
            }

            /*
             * tenant_id hiçbir şekilde request üzerinden değiştirilemez.
             */
            unset($data['tenant_id']);

            /*
             * parent_id update datasında yoksa mevcut parent korunur.
             */
            if (! array_key_exists('parent_id', $data)) {
                return $this->repository->update(
                    $organization,
                    $data
                );
            }

            $parentId = $data['parent_id'];

            /*
             * Organization root yapılmak isteniyor.
             */
            if ($parentId === null) {
                $anotherRootExists = Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('parent_id')
                    ->where('id', '!=', $organization->id)
                    ->exists();

                if ($anotherRootExists) {
                    throw new RuntimeException(
                        'Bu tenant için zaten bir kök organizasyon bulunmaktadır.'
                    );
                }
            } else {
                /*
                 * Kendisi kendisinin parent'ı olamaz.
                 */
                if ((int) $parentId === (int) $organization->id) {
                    throw new RuntimeException(
                        'Bir organizasyon kendisini üst organizasyon olarak belirleyemez.'
                    );
                }

                /*
                 * Parent aynı tenant'a ait olmalıdır.
                 */
                $parentExists = Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($parentId)
                    ->exists();

                if (! $parentExists) {
                    throw new RuntimeException(
                        'Seçilen üst organizasyon bu tenant içerisinde bulunamadı.'
                    );
                }
            }

            return $this->repository->update(
                $organization,
                $data
            );
        });
    }

    public function delete(Organization $organization): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException(
                'Tenant context has not been initialized.'
            );
        }

        DB::transaction(function () use ($organization) {
            if ($organization->tenant_id !== $this->tenantContext->id()) {
                throw new RuntimeException(
                    'Bu organizasyona erişim yetkiniz yok.'
                );
            }

            $this->repository->delete($organization);
        });
    }
}