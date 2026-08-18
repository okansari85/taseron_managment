<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class TenantOnboardingService
{
    public function __construct(
        private TenantService $tenantService,
        private OrganizationService $organizationService,
        private CompanyService $companyService,
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
             * 1. Tenant oluştur
             */
            $tenant = $this->tenantService->create(
                $data['tenant']
            );

            /*
             * 2. Yeni tenant context'i aktif et
             */
            $this->tenantContext->set(
                $tenant
            );

            /*
             * 3. Root Organization oluştur
             */
            $organization = $this->organizationService->create(
                $data['organization']
            );

            /*
             * 4. Company oluştur
             *
             * CompanyService:
             * - BusinessEntity(type=company)
             * - Company
             *
             * kayıtlarını oluşturur.
             */
            $company = $this->companyService->create([
                'name' => $data['company']['name'],
                'company_type' => $data['company']['company_type'],
            ]);

            /*
             * 5. Organization ↔ Company
             */
            $this->organizationCompanyService->attach(
                $organization,
                $company->businessEntity
            );

            /*
             * 6. Şahıs firması ise
             * otomatik merkez lokasyon oluştur.
             *
             * Tüzel kişilikte onboarding sırasında
             * otomatik lokasyon oluşturulmaz.
             */
            $location = null;

            if ($company->company_type === 'individual') {

                $location = $this->locationService->create([
                    'name' => $data['location']['name'],
                ]);

                /*
                 * 7. Organization ↔ Location
                 */
                $this->organizationLocationService->attach(
                    $organization,
                    $location
                );
            }

            /*
             * 8. Onboarding sonucu
             */
            return [
                'tenant' => $tenant,
                'organization' => $organization,
                'company' => $company,
                'location' => $location,
            ];
        });
    }
}
