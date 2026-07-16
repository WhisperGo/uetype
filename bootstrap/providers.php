<?php

use App\Providers\AppServiceProvider;
use App\Providers\UserMonitoringServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    VoltServiceProvider::class,
    UserMonitoringServiceProvider::class,
];
