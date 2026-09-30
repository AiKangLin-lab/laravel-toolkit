<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Integration;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Throwable;

/**
 * 第三方集成异常。
 *
 * 携带失败分类与可选的 HTTP / 第三方错误码。
 * 已由 IntegrationLogger 记录，不再交给 Laravel 默认异常日志（避免 previous 泄密与重复记录）。
 * 不把原始异常链式挂到 previous，仅保留类名供诊断。
 */
final class IntegrationException extends RuntimeException implements ShouldntReport
{
    /**
     * 原始异常类名（仅诊断，不含消息）。
     */
    public readonly ?string $previousClass;

    /**
     * @param string $message 可安全对外说明的错误文案
     * @param FailureType $failureType 失败类型
     * @param int|null $httpStatus HTTP 状态码
     * @param string|int|null $providerCode 第三方业务错误码
     * @param Throwable|null $previous 原始异常（仅取类名，不链式保留）
     */
    public function __construct(
        string $message,
        public readonly FailureType $failureType,
        public readonly ?int $httpStatus = null,
        public readonly string|int|null $providerCode = null,
        ?Throwable $previous = null,
    ) {
        $this->previousClass = $previous !== null ? $previous::class : null;

        parent::__construct($message, 0, null);
    }
}
