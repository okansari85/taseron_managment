<?php

namespace App\Providers;

use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\TenantRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            TenantRepositoryInterface::class,
            TenantRepository::class
        );
    }

    public function boot(): void
    {
        //
    }
}
