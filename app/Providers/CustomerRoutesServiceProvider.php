<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class CustomerRoutesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        require base_path('routes/customers.php');
    }
}
