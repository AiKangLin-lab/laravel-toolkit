<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Providers;

use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Valencio\LaravelToolkit\Integration\Logging\IntegrationLogger;
use Valencio\LaravelToolkit\Integration\Logging\LogSanitizer;

/**
 * Laravel Toolkit 服务提供者。
 *
 * 合并配置，并注册集成 HTTP 日志相关服务。
 */
final class LaravelToolkitServiceProvider extends ServiceProvider
{
    /**
     * 注册包服务与配置。
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/laravel-toolkit.php',
            'laravel-toolkit',
        );

        $this->app->singleton(LogSanitizer::class);

        $this->app->singleton(IntegrationLogger::class, function ($app): IntegrationLogger {
            /** @var LogManager $logManager */
            $logManager = $app->make(LogManager::class);

            return new IntegrationLogger(
                channelResolver: static function (?string $channel) use ($logManager) {
                    return $channel !== null && $channel !== ''
                        ? $logManager->channel($channel)
                        : $logManager->driver();
                },
                sanitizer: $app->make(LogSanitizer::class),
            );
        });
    }

    /**
     * 启动包服务（发布配置）。
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/laravel-toolkit.php' => config_path('laravel-toolkit.php'),
            ], 'laravel-toolkit-config');
        }
    }
}
