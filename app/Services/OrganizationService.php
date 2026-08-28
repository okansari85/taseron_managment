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
            throw new LogicException('Tenant context has not been initialized.');
        }

        return DB::transaction(function () use ($data) {
            $tenantId = $this->tenantContext->id();

            $data['tenant_id'] = $tenantId;
            $data['parent_id'] ??= null;

            if ($data['parent_id'] === null) {
                $root = $this->repository->getRootByTenantId($tenantId);

                if ($root) {
                    $data['parent_id'] = $root->id;
                }
            }

            if ($data['parent_id'] !== null) {
                $parent = Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->find($data['parent_id']);

                if (! $parent) {
                    throw new RuntimeException(
                        'Seçilen üst organizasyon bu tenant içerisinde bulunamadı.'
                    );
                }

                if (($data['type'] ?? null) === 'group' && ! in_array($parent->type, ['holding', 'group'], true)) {
                    throw new RuntimeException(
                        'Bir grup yalnızca holding veya grup altında oluşturulabilir.'
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
            throw new LogicException('Tenant context has not been initialized.');
        }

        return DB::transaction(function () use ($organization, $data) {
            $tenantId = $this->tenantContext->id();

            if ($organization->tenant_id !== $tenantId) {
                throw new RuntimeException('Bu organizasyona erişim yetkiniz yok.');
            }

            // Company/brand organizations are derived relationship nodes. They
            // must be moved/removed through their owning relationship services.
            if (in_array($organization->type, ['company', 'brand'], true)) {
                throw new RuntimeException(
                    'Company ve Brand düğümleri doğrudan düzenlenemez; ilgili ilişki üzerinden yönetilir.'
                );
            }

            unset($data['tenant_id']);

            if (! array_key_exists('parent_id', $data)) {
                return $this->repository->update($organization, $data);
            }

            $parentId = $data['parent_id'];

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
                if ((int) $parentId === (int) $organization->id) {
                    throw new RuntimeException(
                        'Bir organizasyon kendisini üst organizasyon olarak belirleyemez.'
                    );
                }

                $parent = Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->find($parentId);

                if (! $parent) {
                    throw new RuntimeException(
                        'Seçilen üst organizasyon bu tenant içerisinde bulunamadı.'
                    );
                }

                if ($organization->type === 'group' && ! in_array($parent->type, ['holding', 'group'], true)) {
                    throw new RuntimeException(
                        'Bir grup yalnızca holding veya grup altında bulunabilir.'
                    );
                }
            }

            return $this->repository->update($organization, $data);
        });
    }

    public function delete(Organization $organization): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        DB::transaction(function () use ($organization) {
            if ($organization->tenant_id !== $this->tenantContext->id()) {
                throw new RuntimeException('Bu organizasyona erişim yetkiniz yok.');
            }

            // Derived nodes are owned by company/brand relationship services.
            if (in_array($organization->type, ['company', 'brand'], true)) {
                throw new RuntimeException(
                    'Company ve Brand düğümleri doğrudan silinemez; ilgili ilişki üzerinden silinir.'
                );
            }

            if ($organization->children()->exists()) {
                throw new RuntimeException(
                    'Alt organizasyonları bulunan bir organizasyon silinemez.'
                );
            }

            if (DB::table('organization_locations')
                ->where('organization_id', $organization->id)
                ->exists()) {
                throw new RuntimeException(
                    'Lokasyon bağlantısı bulunan bir organizasyon silinemez.'
                );
            }

            $this->repository->delete($organization);
        });
    }
}
