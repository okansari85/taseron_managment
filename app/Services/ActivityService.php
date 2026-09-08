<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class ActivityService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function all(): Collection
    {
        return Activity::query()
            ->withCount(['locationBusinessEntities'])
            ->orderBy('name')
            ->get()
            ->each(function (Activity $activity) {
                $activity->setAttribute(
                    'required_company_documents_count',
                    $activity->documentTypes()->where('target', 'company')->where('is_required', true)->count()
                );
                $activity->setAttribute(
                    'required_personnel_documents_count',
                    $activity->documentTypes()->where('target', 'personnel')->where('is_required', true)->count()
                );
            });
    }

    public function find(int $id): Activity
    {
        return Activity::query()
            ->withCount(['locationBusinessEntities'])
            ->findOrFail($id);
    }

    public function create(array $data): Activity
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        return DB::transaction(function () use ($data) {
            $data['tenant_id'] = $this->tenantContext->id();

            return Activity::query()->create($data);
        });
    }

    public function update(Activity $activity, array $data): Activity
    {
        $this->assertOwnership($activity);

        $activity->update($data);

        return $activity->refresh();
    }

    public function delete(Activity $activity): void
    {
        $this->assertOwnership($activity);

        if ($activity->locationBusinessEntities()->exists()) {
            throw new RuntimeException('Bu faaliyeti kullanan firmalar var, silinemez.');
        }

        $activity->delete();
    }

    private function assertOwnership(Activity $activity): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        if ($activity->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu faaliyete erişim yetkiniz yok.');
        }
    }
}
