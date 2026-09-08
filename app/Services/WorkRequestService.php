<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Contractor;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class WorkRequestService
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    public function list(?int $contractorId = null): Collection
    {
        $this->assertTenantContext();

        return WorkRequest::query()
            ->when($contractorId, fn ($query) => $query->where('contractor_id', $contractorId))
            ->with(['contractor.businessEntity', 'organization:id,name,type', 'location:id,name', 'requestedByUser:id,name'])
            ->orderByDesc('id')
            ->get();
    }

    public function create(array $data, ?User $actingUser): WorkRequest
    {
        $this->assertTenantContext();
        $tenantId = $this->tenantContext->id();

        $contractor = Contractor::query()->findOrFail($data['contractor_id']);
        $this->assertTenantContractor($contractor);

        if (! empty($data['organization_id'])) {
            $organization = Organization::query()->where('tenant_id', $tenantId)->find($data['organization_id']);
            if (! $organization) {
                throw ValidationException::withMessages(['organization_id' => 'Seçilen organizasyon mevcut tenant kapsamında değil.']);
            }
        }

        if (! empty($data['location_id'])) {
            $location = Location::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($data['location_id']);
            if (! $location) {
                throw ValidationException::withMessages(['location_id' => 'Seçilen lokasyon mevcut tenant kapsamında değil.']);
            }
        }

        $workRequest = WorkRequest::query()->create([
            'tenant_id' => $tenantId,
            'contractor_id' => $contractor->id,
            'organization_id' => $data['organization_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'requested_date' => $data['requested_date'] ?? null,
            'status' => 'pending',
            'requested_by_name' => $data['requested_by_name'] ?? $actingUser?->name,
            'requested_by_user_id' => $actingUser?->id,
        ]);

        return $workRequest->load(['contractor.businessEntity', 'organization:id,name,type', 'location:id,name', 'requestedByUser:id,name']);
    }

    /**
     * Taşeron rolündeki bir kullanıcının SADECE kendi taşeronuna ait iş taleplerini listeler.
     */
    public function listForContractorUser(int $contractorId): Collection
    {
        $this->assertTenantContext();

        return WorkRequest::query()
            ->where('contractor_id', $contractorId)
            ->with(['contractor.businessEntity', 'organization:id,name,type', 'location:id,name', 'requestedByUser:id,name'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Taşeron, kendisine açılan bir iş talebi için (henüz karara bağlanmamışsa) alternatif bir tarih önerir.
     */
    public function proposeDate(WorkRequest $workRequest, int $contractorId, string $proposedDate): WorkRequest
    {
        $this->assertTenantWorkRequest($workRequest);

        if ($workRequest->contractor_id !== $contractorId) {
            throw new RuntimeException('Bu iş talebi bu taşerona ait değil.');
        }

        if (! in_array($workRequest->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => 'Bu durumdaki bir iş talebi için tarih önerilemez.']);
        }

        $workRequest->update(['proposed_date' => $proposedDate]);

        return $workRequest->load(['contractor.businessEntity', 'organization:id,name,type', 'location:id,name', 'requestedByUser:id,name']);
    }

    /**
     * Talebi açan taraf, taşeronun önerdiği tarihi kabul eder — planlanan tarih güncellenir, öneri temizlenir.
     */
    public function acceptProposedDate(WorkRequest $workRequest): WorkRequest
    {
        $this->assertTenantWorkRequest($workRequest);

        if (! $workRequest->proposed_date) {
            throw ValidationException::withMessages(['proposed_date' => 'Kabul edilecek bir tarih önerisi yok.']);
        }

        $workRequest->update([
            'requested_date' => $workRequest->proposed_date,
            'proposed_date' => null,
        ]);

        return $workRequest->load(['contractor.businessEntity', 'organization:id,name,type', 'location:id,name', 'requestedByUser:id,name']);
    }

    public function updateStatus(WorkRequest $workRequest, string $status): WorkRequest
    {
        $this->assertTenantWorkRequest($workRequest);

        $workRequest->update(['status' => $status]);

        return $workRequest->load(['contractor.businessEntity', 'organization:id,name,type', 'location:id,name', 'requestedByUser:id,name']);
    }

    public function delete(WorkRequest $workRequest): void
    {
        $this->assertTenantWorkRequest($workRequest);
        $workRequest->delete();
    }

    private function assertTenantContext(): void
    {
        if (! $this->tenantContext->has()) {
            throw new LogicException('Tenant context has not been initialized.');
        }
    }

    private function assertTenantContractor(Contractor $contractor): void
    {
        $tenantId = $contractor->businessEntity?->tenant_id ?? $contractor->businessEntity()->value('tenant_id');
        if ($tenantId !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu taşeron mevcut tenant kapsamında değil.');
        }
    }

    private function assertTenantWorkRequest(WorkRequest $workRequest): void
    {
        $this->assertTenantContext();
        if ($workRequest->tenant_id !== $this->tenantContext->id()) {
            throw new RuntimeException('Bu iş talebine erişim yetkiniz yok.');
        }
    }
}
