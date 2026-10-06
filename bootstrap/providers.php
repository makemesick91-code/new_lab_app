<?php

use App\Modules\Observability\Providers\ObservabilityServiceProvider;
use App\Modules\Satusehat\Providers\SatusehatServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\RepositoryServiceProvider;

return [
    AppServiceProvider::class,
    RepositoryServiceProvider::class,
    SatusehatServiceProvider::class,
    ObservabilityServiceProvider::class,
];
