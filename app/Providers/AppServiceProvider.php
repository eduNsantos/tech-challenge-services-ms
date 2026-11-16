<?php

namespace App\Providers;

use App\Application\Telemetry\Metrics;
use App\Application\Telemetry\ServiceOrderJourney;
use App\Domain\ServiceOrder\ServiceOrderContract;
use App\Domain\ServiceOrder\ServiceOrderServiceContract;
use App\Infrastructure\Repositories\EloquentServiceOrderRepository;
use App\Infrastructure\Repositories\EloquentServiceOrderServiceRepository;
use App\Infrastructure\Telemetry\LoggerProviderFactory;
use App\Infrastructure\Telemetry\MeterProviderFactory;
use Aws\Sqs\SqsClient;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ServiceOrderContract::class, EloquentServiceOrderRepository::class);
        $this->app->bind(ServiceOrderServiceContract::class, EloquentServiceOrderServiceRepository::class);

        $this->app->singleton(SqsClient::class, function (): SqsClient {
            $config = ['version' => 'latest', 'region' => config('services.sqs.region')];

            if ($endpoint = config('services.sqs.endpoint')) {
                $config['endpoint'] = $endpoint;
            }

            return new SqsClient($config);
        });

        $this->app->singleton(
            MeterProviderInterface::class,
            fn (): MeterProviderInterface => MeterProviderFactory::create(config('telemetry')),
        );
        $this->app->singleton(Metrics::class);

        $this->app->singleton(
            LoggerProviderInterface::class,
            fn (): LoggerProviderInterface => LoggerProviderFactory::create(config('telemetry')),
        );
        $this->app->singleton(ServiceOrderJourney::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API web: exporta métricas e logs da jornada ao terminar a request. Nas Lambdas o
        // processo é reaproveitado entre invocações, então os handlers chamam flush().
        $this->app->terminating(function (): void {
            if ($this->app->resolved(Metrics::class)) {
                $this->app->make(Metrics::class)->flush();
            }

            if ($this->app->resolved(ServiceOrderJourney::class)) {
                $this->app->make(ServiceOrderJourney::class)->flush();
            }
        });
    }
}
