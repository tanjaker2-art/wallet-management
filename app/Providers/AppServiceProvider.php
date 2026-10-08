<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\WalletService;
use App\Repositories\EloquentWalletRepository;
use App\Repositories\WalletRepository;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(WalletRepository::class, EloquentWalletRepository::class);
    }

    public function boot()
    {
        //
    }
}
