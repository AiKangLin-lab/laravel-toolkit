<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Integration\Logging;

use InvalidArgumentException;

/**
 * 出站 HTTP 日志策略。
 *
 * 由包默认配置生成，具体客户端可覆盖部分字段。
 */
final readonly class HttpLogOptions
{
    /**
     * @param string|null $channel 日志通道；null 表示项目默认通道
     * @param bool $logSuccess 是否记录成功请求
     * @param LogContentMode $requestBody 请求参数记录策略
     * @param LogContentMode $responseBody 响应内容记录策略
     * @param list<string> $sensitiveKeys 需脱敏的字段名（大小写不敏感）
     * @param list<string> $allowedHeaders 允许写入日志的请求头
     * @param int $maxContentLength 单段内容最大字符数
     */
    public function __construct(
        public ?string $channel = null,
        public bool $logSuccess = false,
        public LogContentMode $requestBody = LogContentMode::FailureOnly,
        public LogContentMode $responseBody = LogContentMode::FailureOnly,
        public array $sensitiveKeys = [
            'authorization',
            'password',
            'passwd',
            'secret',
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'apikey',
            'key',
        ],
        public array $allowedHeaders = [
            'Accept',
            'Content-Type',
            'User-Agent',
        ],
        public int $maxContentLength = 2000,
    ) {
    }

    /**
     * 从包配置数组构建默认策略。
     *
     * - sensitive_keys：整表替换默认列表
     * - sensitive_keys_extra：在默认（或替换后）列表上追加
     *
     * @param array<string, mixed> $config 日志配置
     *
     * @throws InvalidArgumentException 配置非法时
     */
    public static function fromConfig(array $config): self
    {
        $defaults = new self;

        return new self(
            channel: array_key_exists('channel', $config) && $config['channel'] !== null && $config['channel'] !== ''
                ? (string) $config['channel']
                : null,
            logSuccess: (bool) ($config['log_success'] ?? false),
            requestBody: self::contentMode($config['request_body'] ?? 'failure_only', 'request_body'),
            responseBody: self::contentMode($config['response_body'] ?? 'failure_only', 'response_body'),
            sensitiveKeys: self::resolveSensitiveKeys($config, $defaults->sensitiveKeys),
            allowedHeaders: array_values(array_map(
                static fn (mixed $header): string => (string) $header,
                $config['allowed_headers'] ?? $defaults->allowedHeaders,
            )),
            maxContentLength: self::positiveInt(
                $config['max_content_length'] ?? 2000,
                'max_content_length',
            ),
        );
    }

    /**
     * 以当前策略为底，覆盖部分字段。
     *
     * @param string|null $channel 日志通道；传入非 null 时覆盖
     * @param bool|null $logSuccess 是否记录成功
     * @param LogContentMode|null $requestBody 请求体策略
     * @param LogContentMode|null $responseBody 响应体策略
     * @param list<string>|null $sensitiveKeys 脱敏字段（整表替换）
     * @param list<string>|null $allowedHeaders 允许的请求头
     * @param int|null $maxContentLength 内容长度限制
     */
    public function with(
        ?string $channel = null,
        ?bool $logSuccess = null,
        ?LogContentMode $requestBody = null,
        ?LogContentMode $responseBody = null,
        ?array $sensitiveKeys = null,
        ?array $allowedHeaders = null,
        ?int $maxContentLength = null,
    ): self {
        return new self(
            channel: $channel ?? $this->channel,
            logSuccess: $logSuccess ?? $this->logSuccess,
            requestBody: $requestBody ?? $this->requestBody,
            responseBody: $responseBody ?? $this->responseBody,
            sensitiveKeys: $sensitiveKeys ?? $this->sensitiveKeys,
            allowedHeaders: $allowedHeaders ?? $this->allowedHeaders,
            maxContentLength: $maxContentLength ?? $this->maxContentLength,
        );
    }

    /**
     * 在现有脱敏字段上追加，不替换默认保护。
     *
     * @param list<string> $keys 追加字段
     */
    public function withExtraSensitiveKeys(array $keys): self
    {
        $merged = array_values(array_unique([
            ...$this->sensitiveKeys,
            ...array_map(static fn (mixed $key): string => (string) $key, $keys),
        ]));

        return $this->with(sensitiveKeys: $merged);
    }

    /**
     * 是否应记录请求参数。
     */
    public function shouldLogRequestBody(bool $isFailure): bool
    {
        return $this->shouldLog($this->requestBody, $isFailure);
    }

    /**
     * 是否应记录响应内容。
     */
    public function shouldLogResponseBody(bool $isFailure): bool
    {
        return $this->shouldLog($this->responseBody, $isFailure);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $defaults
     * @return list<string>
     */
    private static function resolveSensitiveKeys(array $config, array $defaults): array
    {
        if (array_key_exists('sensitive_keys', $config) && ! is_array($config['sensitive_keys'])) {
            throw new InvalidArgumentException(
                'Invalid laravel-toolkit logging.sensitive_keys; expected array.',
            );
        }

        if (array_key_exists('sensitive_keys_extra', $config) && ! is_array($config['sensitive_keys_extra'])) {
            throw new InvalidArgumentException(
                'Invalid laravel-toolkit logging.sensitive_keys_extra; expected array.',
            );
        }

        $base = array_key_exists('sensitive_keys', $config)
            ? array_values(array_map(
                static fn (mixed $key): string => (string) $key,
                $config['sensitive_keys'],
            ))
            : $defaults;

        $extra = [];
        if (isset($config['sensitive_keys_extra'])) {
            $extra = array_map(
                static fn (mixed $key): string => (string) $key,
                $config['sensitive_keys_extra'],
            );
        }

        return array_values(array_unique([...$base, ...$extra]));
    }

    /**
     * @param mixed $value 配置值
     * @param string $key 配置键名
     */
    private static function contentMode(mixed $value, string $key): LogContentMode
    {
        if ($value instanceof LogContentMode) {
            return $value;
        }

        $mode = LogContentMode::tryFrom((string) $value);

        if ($mode === null) {
            throw new InvalidArgumentException(
                "Invalid laravel-toolkit logging.{$key} value [{$value}].",
            );
        }

        return $mode;
    }

    /**
     * @param mixed $value 配置值
     * @param string $key 配置键名
     */
    private static function positiveInt(mixed $value, string $key): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException(
                "Invalid laravel-toolkit logging.{$key}; expected positive integer.",
            );
        }

        return (int) $value;
    }

    private function shouldLog(LogContentMode $mode, bool $isFailure): bool
    {
        return match ($mode) {
            LogContentMode::Never => false,
            LogContentMode::Always => true,
            LogContentMode::FailureOnly => $isFailure,
        };
    }
}
