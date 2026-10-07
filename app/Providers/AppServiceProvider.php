<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\WalletService;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(WalletService::class, function ($app) {
            return new WalletService($app['db']);
        });
    }

    public function boot()
    {
        //
    }
}
