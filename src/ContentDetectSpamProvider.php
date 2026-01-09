<?php

declare(strict_types=1);

namespace SharpAPI\ContentDetectSpam;

use Illuminate\Support\ServiceProvider;

/**
 * @api
 */
class ContentDetectSpamProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sharpapi-content-detect-spam.php' => config_path('sharpapi-content-detect-spam.php'),
            ], 'sharpapi-content-detect-spam');
        }
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        // Merge the package configuration with the app configuration.
        $this->mergeConfigFrom(
            __DIR__.'/../config/sharpapi-content-detect-spam.php', 'sharpapi-content-detect-spam'
        );
    }
}