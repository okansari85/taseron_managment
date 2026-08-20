<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TenantOnboardingService
{
    public function __construct(
        private TenantService $tenantService,
        private OrganizationService $organizationService,
        private CompanyService $companyService,
        private LocationService $locationService,
        private OrganizationCompanyService $organizationCompanyService,
        private TenantContext $tenantContext
    ) {
    }

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $onboardingType = $data['onboarding_type'] ?? null;

            if (! in_array(
                $onboardingType,
                ['holding', 'group', 'company'],
                true
            )) {
                throw new InvalidArgumentException(
                    'Geçersiz onboarding tipi.'
                );
            }

            $tenant = $this->tenantService->create(
                $data['tenant']
            );

            $this->tenantContext->set(
                $tenant
            );

            $organizationData = $data['organization'];
            $organizationData['type'] = in_array(
                $onboardingType,
                ['holding', 'group'],
                true
            )
                ? $onboardingType
                : null;

            $organization = $this->organizationService->create(
                $organizationData
            );

            if (
                $onboardingType === 'holding'
                || $onboardingType === 'group'
            ) {
                return [
                    'tenant' => $tenant,
                    'organization' => $organization,
                    'company' => null,
                    'location' => null,
                ];
            }

            $company = $this->companyService->create([
                'name' => $data['company']['name'],
                'company_type' => $data['company']['company_type'],
            ]);

            $this->organizationCompanyService->attach(
                $organization,
                $company->businessEntity
            );

            $location = null;

            if ($company->company_type === 'individual') {
                $location = $this->locationService->create([
                    'name' => $data['location']['name'],
                ]);
            }

            return [
                'tenant' => $tenant,
                'organization' => $organization,
                'company' => $company,
                'location' => $location,
            ];
        });
    }
}
