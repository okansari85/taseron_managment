<?php

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, function () {
            return new TenantContext();
        });
    }

    public function boot(): void
    {
        //
    }
}
