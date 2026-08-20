<?php

namespace App\Providers;

use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Repositories\OrganizationRepository;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\TenantRepository;
use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use App\Repositories\CompanyRepository;
use App\Repositories\Contracts\LocationRepositoryInterface;
use App\Repositories\LocationRepository;
use App\Repositories\OrganizationCompanyRepository;
use App\Repositories\Contracts\OrganizationCompanyRepositoryInterface;
use App\Repositories\Contracts\LocationBusinessEntityRepositoryInterface;
use App\Repositories\LocationBusinessEntityRepository;
use App\Repositories\Contracts\ContractorRepositoryInterface;
use App\Repositories\ContractorRepository;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\BrandRepository;
use App\Repositories\Contracts\OperationalUnitRepositoryInterface;
use App\Repositories\OperationalUnitRepository;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TenantRepositoryInterface::class, TenantRepository::class);
        $this->app->bind(OrganizationRepositoryInterface::class, OrganizationRepository::class);
        $this->app->bind(CompanyRepositoryInterface::class, CompanyRepository::class);
        $this->app->bind(LocationRepositoryInterface::class, LocationRepository::class);
        $this->app->bind(OrganizationCompanyRepositoryInterface::class, OrganizationCompanyRepository::class);
        $this->app->bind(LocationBusinessEntityRepositoryInterface::class, LocationBusinessEntityRepository::class);
        $this->app->bind(ContractorRepositoryInterface::class, ContractorRepository::class);
        $this->app->bind(BrandRepositoryInterface::class, BrandRepository::class);
        $this->app->bind(OperationalUnitRepositoryInterface::class, OperationalUnitRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
