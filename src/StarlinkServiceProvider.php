<?php

namespace FLAIRUK\Starlink;

use FLAIRUK\Starlink\Commands\TelemetryCommand;
use Illuminate\Support\ServiceProvider;

class StarlinkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/starlink.php', 'starlink');

        $this->app->singleton(Starlink::class, fn ($app) => new Starlink(
            $app['config']['starlink'],
            $app['cache'],
            $app['events'],
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TelemetryCommand::class]);

            $this->publishes([
                __DIR__.'/../config/starlink.php' => config_path('starlink.php'),
            ], 'starlink-config');
        }
    }
}
