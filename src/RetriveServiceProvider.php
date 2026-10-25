<?php

declare(strict_types=1);

namespace Atannex\Retrive;

use Atannex\Retrive\Console\ClearCommand;
use Atannex\Retrive\Console\ListCommand;
use Atannex\Retrive\Console\MakeResourceCommand;
use Atannex\Retrive\Console\SqlCommand;
use Illuminate\Support\ServiceProvider;

class RetriveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/retrive.php', 'retrive');

        $this->app->singleton(RetrievalManager::class, fn ($app) => (new RetrievalManager(
            $app,
            $app['config'],
            $app['cache'],
            $app['events'],
        ))->registerMany((array) $app['config']->get('retrive.resources', [])));

        $this->app->alias(RetrievalManager::class, 'retrive');
    }

    public function boot(): void
    {
        // Resolve once booted so every resource's models are watched from
        // the start: a write must invalidate the cache even in a request
        // that never retrieves anything.
        $this->app->booted(fn ($app) => $app->make(RetrievalManager::class));

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/retrive.php' => config_path('retrive.php')], 'retrive-config');
            $this->publishes([__DIR__.'/../stubs' => base_path('stubs/retrive')], 'retrive-stubs');

            $this->commands([
                ListCommand::class,
                ClearCommand::class,
                SqlCommand::class,
                MakeResourceCommand::class,
            ]);
        }
    }
}
