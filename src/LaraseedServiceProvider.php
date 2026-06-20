<?php

namespace Azwar\Laraseed;

use Azwar\Laraseed\Commands\GenerateAutoFactory;
use Illuminate\Support\ServiceProvider;

/**
 * LaraseedServiceProvider
 *
 * Registers all Artisan commands and publishable assets
 * for the azwar/laravel-auto-generator package.
 */
class LaraseedServiceProvider extends ServiceProvider
{
    /**
     * All Artisan commands provided by this package.
     *
     * @var array<class-string>
     */
    protected array $commands = [
        GenerateAutoFactory::class,
    ];

    /**
     * Bootstrap any application services.
     *
     * Called after all other service providers have been registered,
     * meaning you have access to all other services registered by the framework.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);

            // Publish stubs so users can customise the generated output
            // Publish config file
            $this->publishes([
                __DIR__ . '/../config/laraseed.php' => config_path('laraseed.php'),
            ], 'laraseed-config');

            $this->publishes([
                __DIR__ . '/Stubs' => base_path('stubs/laraseed'),
            ], 'laraseed-stubs');
        }

        // Merge default config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/laraseed.php',
            'laraseed'
        );
    }

    /**
     * Register any application services.
     *
     * This method is called before boot() across all providers.
     * Bind your implementation classes into the IoC container here.
     */
    public function register(): void
    {
        //
    }
}
