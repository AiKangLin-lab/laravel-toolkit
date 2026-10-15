<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Files\Logging;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 文件存储操作日志。
 *
 * 失败默认记录，成功由配置开启。日志写入失败不改变存储结果。
 */
final readonly class FileStorageLogger
{
    /**
     * @param  Closure(string): LoggerInterface  $namedChannel  按通道名解析 Logger；通道不存在时应抛出
     * @param  Closure(): LoggerInterface  $defaultChannel  未指定通道时使用的默认 Logger
     * @param  string|null  $configuredChannel  包配置中的通道；空表示使用默认通道
     * @param  bool  $logSuccess  是否记录成功操作
     * @param  Closure(): ?string  $traceId  当前追踪号；没有请求上下文时返回 null
     */
    public function __construct(
        private Closure $namedChannel,
        private Closure $defaultChannel,
        private ?string $configuredChannel,
        private bool $logSuccess,
        private Closure $traceId,
    ) {}

    /**
     * 在存储 I/O 之前解析本次日志通道。
     *
     * 显式通道优先于包配置，包配置再优先于默认通道。
     *
     * @param  string|null  $logChannel  本次调用指定的通道
     */
    public function open(?string $logChannel): LoggerInterface
    {
        $name = $this->selectedChannel($logChannel);

        if ($name === null) {
            return ($this->defaultChannel)();
        }

        return ($this->namedChannel)($name);
    }

    /**
     * 记录一次存储操作。成功在未开启成功日志时直接返回。
     *
     * @param  LoggerInterface  $logger  已经解析好的通道
     * @param  bool  $failed  本次操作是否失败
     * @param  string  $operation  store 或 delete
     * @param  string  $disk  逻辑 disk 名
     * @param  string  $path  对象相对路径
     * @param  int|null  $size  字节数；删除或写入前失败时可能为空
     * @param  int  $durationMs  耗时毫秒
     * @param  string|null  $exceptionClass  异常类名，不包含异常消息
     * @param  string|null  $providerRequestId  已通过白名单的厂商请求号
     */
    public function record(
        LoggerInterface $logger,
        bool $failed,
        string $operation,
        string $disk,
        string $path,
        ?int $size,
        int $durationMs,
        ?string $exceptionClass = null,
        ?string $providerRequestId = null,
    ): void {
        if (! $failed && ! $this->logSuccess) {
            return;
        }

        try {
            $context = [
                'trace_id' => ($this->traceId)(),
                'operation' => $operation,
                'disk' => $disk,
                'path' => $path,
                'size' => $size,
                'duration_ms' => $durationMs,
                'outcome' => $failed ? 'failure' : 'success',
                'exception_class' => $exceptionClass,
            ];

            if ($providerRequestId !== null) {
                $context['provider_request_id'] = $providerRequestId;
            }

            if ($failed) {
                $logger->error('file_storage', $context);

                return;
            }

            $logger->info('file_storage', $context);
        } catch (Throwable $loggingError) {
            error_log(sprintf(
                '[laravel-toolkit] file storage log failed: %s',
                $loggingError::class,
            ));
        }
    }

    /**
     * 从异常上读取厂商请求号。只接受短的可见标识，不读取异常消息。
     */
    public function providerRequestId(?Throwable $exception): ?string
    {
        if ($exception === null || ! method_exists($exception, 'getRequestId')) {
            return null;
        }

        $requestId = $exception->getRequestId();
        if (! is_string($requestId) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId) !== 1) {
            return null;
        }

        return $requestId;
    }

    private function selectedChannel(?string $logChannel): ?string
    {
        if (is_string($logChannel) && $logChannel !== '') {
            return $logChannel;
        }

        if ($this->configuredChannel !== null && $this->configuredChannel !== '') {
            return $this->configuredChannel;
        }

        return null;
    }
}
