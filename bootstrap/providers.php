<?php

use App\Modules\Satusehat\Providers\SatusehatServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\RepositoryServiceProvider;

return [
    AppServiceProvider::class,
    RepositoryServiceProvider::class,
    SatusehatServiceProvider::class,
];
