<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\OrganizationContractor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class OrganizationContractorService
{
    public function __construct(
        private TenantContext $tenantContext
    ) {
    }

    public function allContractorsForTenant(): Collection
    {
        $tenantId = $this->tenantContext->id();

        return Contractor::query()
            ->whereHas('businessEntity', fn ($query) => $query->where('tenant_id', $tenantId))
            ->with([
                'businessEntity',
                'organizationContractors.organization:id,name,type,parent_id',
            ])
            ->orderBy('id')
            ->get()
            ->each(function (Contractor $contractor) {
                $contractor->setAttribute('name', $contractor->businessEntity?->name ?? '');
                $contractor->setAttribute(
                    'tenant_id',
                    $contractor->businessEntity?->tenant_id
                );
                $contractor->setAttribute(
                    'organizations',
                    $contractor->organizationContractors
                        ->pluck('organization')
                        ->filter()
                        ->values()
                );
            });
    }

    public function list(Organization $organization): Collection
    {
        $this->assertTenantOrganization($organization);

        return OrganizationContractor::query()
            ->where('organization_id', $organization->id)
            ->with('contractor.businessEntity')
            ->get();
    }

    public function attach(Organization $organization, Contractor $contractor): OrganizationContractor
    {
        $this->assertAttachableOrganization($organization);
        $this->assertTenantContractor($contractor);

        return OrganizationContractor::query()->firstOrCreate([
            'organization_id' => $organization->id,
            'contractor_id' => $contractor->id,
        ], [
            'tenant_id' => $this->tenantContext->id(),
        ]);
    }

    public function detach(Organization $organization, Contractor $contractor): void
    {
        $this->assertAttachableOrganization($organization);
        $this->assertTenantContractor($contractor);

        OrganizationContractor::query()
            ->where('organization_id', $organization->id)
            ->where('contractor_id', $contractor->id)
            ->delete();
    }

    private function assertAttachableOrganization(Organization $organization): void
    {
        $this->assertTenantOrganization($organization);

        if (! in_array($organization->type, ['holding', 'group'], true)) {
            throw ValidationException::withMessages([
                'organization' => 'Alt yüklenici yalnızca Holding veya Grup ile eşleştirilebilir.',
            ]);
        }
    }

    private function assertTenantOrganization(Organization $organization): void
    {
        if ($organization->tenant_id !== $this->tenantContext->id()) {
            throw ValidationException::withMessages([
                'organization' => 'Organizasyon mevcut tenant kapsamında değil.',
            ]);
        }
    }

    private function assertTenantContractor(Contractor $contractor): void
    {
        $tenantId = $contractor->businessEntity?->tenant_id
            ?? $contractor->businessEntity()->value('tenant_id');

        if ($tenantId !== $this->tenantContext->id()) {
            throw ValidationException::withMessages([
                'contractor' => 'Alt yüklenici mevcut tenant kapsamında değil.',
            ]);
        }
    }
}
