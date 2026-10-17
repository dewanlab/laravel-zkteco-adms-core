<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Laravel ZKTeco ADMS Core package.
 *
 * Bootstraps and registers package components within the Laravel application:
 * - Configuration: Merges default package configuration from `config/zkteco-adms-core.php`
 *   and provides publishing tagged with `'zkteco-adms-core-config'`.
 * - Database Migrations: Loads and exposes database migrations tagged with `'zkteco-adms-core-migrations'`
 *   for devices, attendance logs, commands, user records, biometric templates, and authorizations.
 * - Routing: Registers ADMS protocol routes defined in `routes/zkteco.php` (e.g. `/iclock/*`).
 */
class LaravelZktecoAdmsCoreServiceProvider extends ServiceProvider
{
    /**
     * Register package services and merge configuration.
     *
     * Merges default configuration from the package into the application's configuration repository.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/zkteco-adms-core.php',
            'zkteco-adms-core'
        );

        $this->registerLoggingChannel();
    }

    /**
     * Register a dedicated logging channel for ZKTeco ADMS communication.
     */
    protected function registerLoggingChannel(): void
    {
        $channelName = (string) config('zkteco-adms-core.logging.channel', 'zkteco-adms');
        if ($channelName === '') {
            return;
        }

        $channels = config('logging.channels', []);
        if (! isset($channels[$channelName])) {
            config([
                "logging.channels.{$channelName}" => [
                    'driver' => 'daily',
                    'path' => config('zkteco-adms-core.logging.path', storage_path('logs/zkteco-adms.log')),
                    'level' => (string) config('zkteco-adms-core.logging.level', 'debug'),
                    'days' => (int) config('zkteco-adms-core.logging.days', 14),
                ],
            ]);
        }
    }

    /**
     * Bootstrap package services, migrations, publishable assets, and routes.
     *
     * In console environments, registers publishable assets:
     * - Config: tagged as `zkteco-adms-core-config`
     * - Migrations: tagged as `zkteco-adms-core-migrations`
     *
     * Also automatically registers package migrations and routes.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/zkteco-adms-core.php' => config_path('zkteco-adms-core.php'),
            ], 'zkteco-adms-core-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'zkteco-adms-core-migrations');

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/zkteco.php');
    }
}
