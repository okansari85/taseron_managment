<?php

namespace App\Services;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

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
        $logoPath = null;

        try {
            return DB::transaction(function () use ($data, &$logoPath) {
                $onboardingType = $data['onboarding_type'] ?? null;

                if (! in_array($onboardingType, ['holding', 'group', 'company'], true)) {
                    throw new InvalidArgumentException('Geçersiz onboarding tipi.');
                }

                $tenant = $this->tenantService->create($data['tenant']);

                if (($data['logo'] ?? null) instanceof UploadedFile) {
                    $logoPath = $data['logo']->store('tenant-logos', 'public');
                    $tenant->update(['logo_path' => $logoPath]);
                    $tenant->refresh();
                }

                $this->tenantContext->set($tenant);

                $organizationData = $data['organization'];
                $organizationData['type'] = in_array($onboardingType, ['holding', 'group'], true)
                    ? $onboardingType
                    : 'group';

                $organization = $this->organizationService->create($organizationData);

                if ($onboardingType === 'holding' || $onboardingType === 'group') {
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

                // OrganizationCompanyService now works with Company directly
                // and creates/preserves the company hierarchy node.
                $this->organizationCompanyService->attach($organization, $company);

                $location = null;

                if ($company->company_type === 'individual') {
                    // Location is a physical entity, not an Organization node.
                    // It is attached to the newly created company node through
                    // the organization_locations pivot.
                    $companyNodeId = DB::table('organization_companies')
                        ->where('organization_id', $organization->id)
                        ->where('company_id', $company->id)
                        ->value('company_node_id');

                    if (! $companyNodeId) {
                        throw new \RuntimeException(
                            'Onboarding sırasında Company node oluşturulamadı.'
                        );
                    }

                    $companyNode = \App\Models\Organization::query()->findOrFail($companyNodeId);

                    $location = $this->organizationLocationService->createForOrganization(
                        $companyNode,
                        ['name' => $data['location']['name']]
                    );
                }

                return [
                    'tenant' => $tenant,
                    'organization' => $organization,
                    'company' => $company,
                    'location' => $location,
                ];
            });
        } catch (Throwable $exception) {
            if ($logoPath !== null) {
                Storage::disk('public')->delete($logoPath);
            }

            throw $exception;
        }
    }
}
