<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Providers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Valencio\LaravelToolkit\Crud\CrudService;
use Valencio\LaravelToolkit\Crud\Query\QueryApplier;
use Valencio\LaravelToolkit\Files\FileKeyGenerator;
use Valencio\LaravelToolkit\Files\FileStorage;
use Valencio\LaravelToolkit\Files\Logging\FileStorageLogger;
use Valencio\LaravelToolkit\Integration\Logging\IntegrationLogger;
use Valencio\LaravelToolkit\Integration\Logging\LogSanitizer;

/**
 * Laravel Toolkit 服务提供者。
 *
 * 合并配置，并注册集成 HTTP、文件存储和 CRUD 服务。
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

        $this->app->singleton(FileKeyGenerator::class);

        $this->app->singleton(FileStorageLogger::class, function ($app): FileStorageLogger {
            /** @var LogManager $logManager */
            $logManager = $app->make(LogManager::class);
            /** @var array<string, mixed> $logging */
            $logging = $app->make('config')->get('laravel-toolkit.files.logging', []);
            $channel = $logging['channel'] ?? null;
            $logSuccess = $logging['log_success'] ?? false;

            if ($channel !== null && ! is_string($channel)) {
                throw new InvalidArgumentException('laravel-toolkit.files.logging.channel must be a string or null.');
            }

            if (! is_bool($logSuccess)) {
                throw new InvalidArgumentException('laravel-toolkit.files.logging.log_success must be a boolean.');
            }

            return new FileStorageLogger(
                namedChannel: static function (string $name) use ($logManager): LoggerInterface {
                    return $logManager->channel($name);
                },
                defaultChannel: static function () use ($logManager): LoggerInterface {
                    return $logManager->driver();
                },
                configuredChannel: is_string($channel) && $channel !== '' ? $channel : null,
                logSuccess: $logSuccess,
                traceId: static function (): ?string {
                    $traceId = Log::sharedContext()['trace_id'] ?? null;

                    return is_string($traceId) && $traceId !== '' ? $traceId : null;
                },
            );
        });

        $this->app->singleton(FileStorage::class, function ($app): FileStorage {
            return new FileStorage(
                disks: $app->make(FilesystemFactory::class),
                keys: $app->make(FileKeyGenerator::class),
                storageLogger: $app->make(FileStorageLogger::class),
            );
        });

        $this->app->singleton(QueryApplier::class);

        $this->app->singleton(CrudService::class, function ($app): CrudService {
            return new CrudService(
                gate: $app->make(Gate::class),
                applier: $app->make(QueryApplier::class),
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
