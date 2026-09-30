<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Integration\Logging;

use Closure;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;
use Throwable;
use Valencio\LaravelToolkit\Integration\FailureType;

/**
 * 集成请求日志记录器。
 *
 * 负责选择通道并组织结构化日志；脱敏由 LogSanitizer 完成。
 * 日志写入失败不得影响主调用结果。
 */
final readonly class IntegrationLogger
{
    /**
     * @param Closure(string|null): LoggerInterface $channelResolver 按通道名解析 Logger
     * @param LogSanitizer $sanitizer 脱敏器
     */
    public function __construct(
        private Closure $channelResolver,
        private LogSanitizer $sanitizer,
    ) {
    }

    /**
     * 按策略记录一次请求结果。失败默认记录；成功受 logSuccess 控制。
     *
     * @param HttpLogOptions $options 日志策略
     * @param string $method HTTP 方法
     * @param string $uri 最终或原始 URI
     * @param string|null $operation 操作标识
     * @param array<string, mixed> $requestOptions 请求快照（含 configureRequest 合并后的 query 等）
     * @param Response|null $response HTTP 响应
     * @param bool $isFailure 是否失败
     * @param FailureType|null $failureType 失败类型
     * @param string|int|null $providerCode 第三方业务码
     * @param int|null $durationMs 耗时
     * @param string|null $exceptionClass 原始异常类名（仅诊断）
     */
    public function log(
        HttpLogOptions $options,
        string $method,
        string $uri,
        ?string $operation,
        array $requestOptions,
        ?Response $response,
        bool $isFailure,
        ?FailureType $failureType = null,
        string|int|null $providerCode = null,
        ?int $durationMs = null,
        ?string $exceptionClass = null,
    ): void {
        if (! $isFailure && ! $options->logSuccess) {
            return;
        }

        try {
            $context = [
                'method' => strtoupper($method),
                'uri' => $this->sanitizer->sanitizeUri($uri, $options),
                'operation' => $operation,
                'status' => $response?->status(),
                'duration_ms' => $durationMs,
                'failure_type' => $failureType?->value,
                'provider_code' => $providerCode,
                'exception_class' => $exceptionClass,
            ];

            if ($options->shouldLogRequestBody($isFailure)) {
                $context['request'] = $this->sanitizer->sanitizeRequestOptions($requestOptions, $options);
            }

            if ($response !== null && $options->shouldLogResponseBody($isFailure)) {
                $context['response'] = $this->sanitizer->sanitizeResponseContent($response, $options);
            }

            $logger = ($this->channelResolver)($options->channel);

            if ($isFailure) {
                $logger->error('integration_http', $context);

                return;
            }

            $logger->info('integration_http', $context);
        } catch (Throwable $loggingError) {
            // 日志故障不得覆盖集成调用结果；仅写最小应急信息
            error_log(sprintf(
                '[laravel-toolkit] integration log failed: %s',
                $loggingError::class,
            ));
        }
    }
}
