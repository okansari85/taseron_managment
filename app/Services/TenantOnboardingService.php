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
        private BrandService $brandService,
        private LocationService $locationService,
        private OrganizationCompanyService $organizationCompanyService,
        private OrganizationLocationService $organizationLocationService,
        private TenantContext $tenantContext
    ) {
    }

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            /*
             * 1. Onboarding type
             */
            $onboardingType = $data['onboarding_type'] ?? null;

            if (! in_array(
                $onboardingType,
                ['holding', 'group', 'company', 'brand'],
                true
            )) {
                throw new InvalidArgumentException(
                    'Geçersiz onboarding tipi.'
                );
            }

            /*
             * 2. Tenant
             */
            $tenant = $this->tenantService->create(
                $data['tenant']
            );

            /*
             * 3. Yeni tenant context'i aktif et
             */
            $this->tenantContext->set(
                $tenant
            );

            /*
             * 4. Root Organization
             *
             * Holding / Group gerçek Organization tipini taşır.
             * Company / Brand başlangıcında Organization kategorisizdir.
             */
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

            /*
             * 5. Holding / Group
             *
             * Sadece Tenant + Organization oluşturulur.
             */
            if (
                $onboardingType === 'holding'
                || $onboardingType === 'group'
            ) {
                return [
                    'tenant' => $tenant,
                    'organization' => $organization,
                    'company' => null,
                    'brand' => null,
                    'location' => null,
                ];
            }

            /*
             * 6. Brand
             *
             * Brand onboarding'inde Company ve Location oluşturulmaz.
             */
            if ($onboardingType === 'brand') {
                $brand = $this->brandService->create([
                    'organization_id' => $organization->id,
                    'name' => $data['brand']['name'],
                ]);

                return [
                    'tenant' => $tenant,
                    'organization' => $organization,
                    'company' => null,
                    'brand' => $brand,
                    'location' => null,
                ];
            }

            /*
             * 7. Company
             *
             * Mevcut Company onboarding akışı korunur.
             */
            $company = $this->companyService->create([
                'name' => $data['company']['name'],
                'company_type' => $data['company']['company_type'],
            ]);

            /*
             * 8. Organization ↔ Company
             */
            $this->organizationCompanyService->attach(
                $organization,
                $company->businessEntity
            );

            /*
             * 9. Şahıs firması ise otomatik merkez Location
             */
            $location = null;

            if ($company->company_type === 'individual') {
                $location = $this->locationService->create([
                    'name' => $data['location']['name'],
                ]);

                /*
                 * 10. Organization ↔ Location
                 */
                $this->organizationLocationService->attach(
                    $organization,
                    $location
                );
            }

            return [
                'tenant' => $tenant,
                'organization' => $organization,
                'company' => $company,
                'brand' => null,
                'location' => $location,
            ];
        });
    }
}
