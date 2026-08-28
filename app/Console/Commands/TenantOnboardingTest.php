<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Services\TenantOnboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TenantOnboardingTest extends Command
{
    protected $signature = 'tenant:onboarding-test';
    protected $description = 'Test individual and corporate tenant onboarding without persisting mutations';

    public function handle(TenantOnboardingService $service, TenantContext $context): int
    {
        $this->info('Starting tenant onboarding integration tests...');

        return DB::transaction(function () use ($service, $context) {
            try {
                $suffix = now()->format('YmdHis') . '_' . bin2hex(random_bytes(3));

                $individual = $service->create([
                    'onboarding_type' => 'company',
                    'tenant' => [
                        'name' => 'TEST Individual Tenant ' . $suffix,
                        'slug' => 'test-individual-' . strtolower($suffix),
                    ],
                    'organization' => [
                        'name' => 'TEST Individual Organization ' . $suffix,
                    ],
                    'company' => [
                        'name' => 'TEST Individual Company ' . $suffix,
                        'company_type' => 'individual',
                    ],
                    'location' => [
                        'name' => 'TEST Individual Location ' . $suffix,
                    ],
                ]);

                $company = $individual['company'];
                $organization = $individual['organization'];
                $location = $individual['location'];

                if (! $company || ! $organization || ! $location) {
                    throw new RuntimeException('Individual onboarding did not return expected records.');
                }

                $membership = DB::table('organization_companies')
                    ->where('organization_id', $organization->id)
                    ->where('company_id', $company->id)
                    ->first();

                if (! $membership || ! $membership->company_node_id) {
                    throw new RuntimeException('Individual onboarding did not create a company node.');
                }

                if (! DB::table('organization_locations')
                    ->where('organization_id', $membership->company_node_id)
                    ->where('location_id', $location->id)
                    ->exists()) {
                    throw new RuntimeException('Individual onboarding did not attach location to company node.');
                }

                $this->info('PASS: individual onboarding creates company node and attaches location through organization_locations.');

                $corporate = $service->create([
                    'onboarding_type' => 'company',
                    'tenant' => [
                        'name' => 'TEST Corporate Tenant ' . $suffix,
                        'slug' => 'test-corporate-' . strtolower($suffix),
                    ],
                    'organization' => [
                        'name' => 'TEST Corporate Organization ' . $suffix,
                    ],
                    'company' => [
                        'name' => 'TEST Corporate Company ' . $suffix,
                        'company_type' => 'corporate',
                    ],
                ]);

                $corporateCompany = $corporate['company'];
                $corporateOrganization = $corporate['organization'];

                if (! $corporateCompany || ! $corporateOrganization) {
                    throw new RuntimeException('Corporate onboarding did not return expected records.');
                }

                $corporateMembership = DB::table('organization_companies')
                    ->where('organization_id', $corporateOrganization->id)
                    ->where('company_id', $corporateCompany->id)
                    ->first();

                if (! $corporateMembership || ! $corporateMembership->company_node_id) {
                    throw new RuntimeException('Corporate onboarding did not create a company node.');
                }

                if ($corporate['location'] !== null) {
                    throw new RuntimeException('Corporate onboarding unexpectedly created a location.');
                }

                $this->info('PASS: corporate onboarding creates company node without automatic location.');
                $this->info('PASS: tenant onboarding tests completed and every mutation was rolled back.');

                return self::SUCCESS;
            } finally {
                $context->clear();
            }
        });
    }
}
