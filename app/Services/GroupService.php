<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Repositories\Contracts\GroupRepositoryInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class GroupService
{
    public function __construct(
        private GroupRepositoryInterface $repository,
        private TenantContext $tenantContext
    ) {
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
            $data['type'] = 'group';
            $data['parent_id'] ??= null;
            $data['display_order'] ??= 0;
            $data['is_active'] = (bool) ($data['is_active'] ?? true);

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
                $parent = Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->find($data['parent_id']);

                if (! $parent) {
                    throw new RuntimeException(
                        'Seçilen üst organizasyon bu tenant içerisinde bulunamadı.'
                    );
                }

                if (! in_array($parent->type, ['holding', 'group'], true)) {
                    throw new RuntimeException(
                        'Bir grup yalnızca holding veya grup altında oluşturulabilir.'
                    );
                }
            }

            return $this->repository->createGroup($data);
        });
    }
}
