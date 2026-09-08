<?php

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\WorkspaceContext;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, function () {
            return new TenantContext();
        });

        $this->app->singleton(WorkspaceContext::class, function () {
            return new WorkspaceContext();
        });
    }

    public function boot(): void
    {
        //
    }
}
