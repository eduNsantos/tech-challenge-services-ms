<?php

namespace App\Providers;

use App\Domain\ServiceOrder\ServiceOrderContract;
use App\Infrastructure\Repositories\EloquentServiceOrderRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ServiceOrderContract::class, EloquentServiceOrderRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
