<?php

namespace App\Providers;

use App\Domain\ServiceOrder\ServiceOrderContract;
use App\Domain\ServiceOrder\ServiceOrderServiceContract;
use App\Infrastructure\Repositories\EloquentServiceOrderRepository;
use App\Infrastructure\Repositories\EloquentServiceOrderServiceRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ServiceOrderContract::class, EloquentServiceOrderRepository::class);
        $this->app->bind(ServiceOrderServiceContract::class, EloquentServiceOrderServiceRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
