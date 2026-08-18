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



class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            TenantRepositoryInterface::class,
            TenantRepository::class
        );

         $this->app->bind(
            OrganizationRepositoryInterface::class,
            OrganizationRepository::class
        );

         $this->app->bind(
            CompanyRepositoryInterface::class,
            CompanyRepository::class
        );

        $this->app->bind(
            LocationRepositoryInterface::class,
            LocationRepository::class
        );
    }

    public function boot(): void
    {
        //
    }
}
