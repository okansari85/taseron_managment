<?php

use App\Providers\AppServiceProvider;
use App\Providers\CustomerRoutesServiceProvider;
use App\Providers\TenancyServiceProvider;


return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    CustomerRoutesServiceProvider::class,
    \App\Providers\PkBillingServiceProvider::class,

];
