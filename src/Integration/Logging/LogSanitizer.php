<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Integration\Logging;

use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;

/**
 * 日志脱敏器。
 *
 * 只处理用于日志的数据副本，不修改真正发出的请求；
 * 读取响应正文时仅处理可回退流，并在读取后恢复游标。
 */
final class LogSanitizer
{
    /**
     * 脱敏任意结构化数据。
     *
     * @param mixed $value 原始值
     * @param HttpLogOptions $options 日志策略
     * @return mixed 脱敏后的副本
     */
    public function sanitize(mixed $value, HttpLogOptions $options): mixed
    {
        return $this->sanitizeValue($value, $options, null);
    }

    /**
     * 脱敏 URI（含 query 中的敏感键）。
     */
    public function sanitizeUri(string $uri, HttpLogOptions $options): string
    {
        $parts = parse_url($uri);

        if ($parts === false) {
            return '[uri omitted: unparseable]';
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            $query = $this->sanitizeValue($query, $options, 'query');
            if (! is_array($query)) {
                $query = [];
            }
        }

        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }
        if (isset($parts['user'])) {
            $rebuilt .= $this->isSensitiveKey('user', $options) ? '***' : $parts['user'];
            if (isset($parts['pass'])) {
                $rebuilt .= ':***';
            }
            $rebuilt .= '@';
        }
        $rebuilt .= $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query);
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $this->truncate($rebuilt, $options);
    }

    /**
     * 脱敏请求选项中适合落日志的部分。
     *
     * @param array<string, mixed> $options Laravel HTTP 请求选项
     * @param HttpLogOptions $logOptions 日志策略
     * @return array<string, mixed>
     */
    public function sanitizeRequestOptions(array $options, HttpLogOptions $logOptions): array
    {
        $payload = [];

        foreach (['query', 'json', 'form_params'] as $key) {
            if (! array_key_exists($key, $options)) {
                continue;
            }

            $payload[$key] = $this->sanitizeQueryOrBody($options[$key], $logOptions, $key);
        }

        if (array_key_exists('multipart', $options)) {
            $payload['multipart'] = $this->sanitizeMultipart($options['multipart'], $logOptions);
        }

        if (isset($options['headers']) && is_array($options['headers'])) {
            $payload['headers'] = $this->sanitizeHeaders($options['headers'], $logOptions);
        }

        if (array_key_exists('body', $options)) {
            $payload['body'] = $this->sanitizeRawBody($options['body'], $logOptions);
        }

        return $payload;
    }

    /**
     * 安全读取并脱敏响应正文；不可回退流则省略。
     * 先回到流开头再读，finally 恢复游标；只读取有上限的字节，避免整包进内存。
     */
    public function sanitizeResponseContent(Response $response, HttpLogOptions $options): mixed
    {
        $stream = $response->toPsrResponse()->getBody();

        if (! $stream->isReadable()) {
            return '[response body omitted]';
        }

        if (! $stream->isSeekable()) {
            return '[response body omitted: non-seekable stream]';
        }

        $position = $stream->tell();

        try {
            $stream->rewind();
            // 多读一点便于 JSON 截断判断，硬顶 64KiB，避免巨包进内存
            $readLimit = min(max($options->maxContentLength * 4, $options->maxContentLength), 65_536);
            $raw = $stream->read($readLimit);
            $truncated = ! $stream->eof();

            if ($raw === '' || $raw === false) {
                return '';
            }

            $sanitized = $this->sanitizeRawBody($raw, $options);

            if ($truncated && is_string($sanitized) && ! str_contains($sanitized, '[truncated]')) {
                return $this->truncate($sanitized, $options);
            }

            return $sanitized;
        } finally {
            $stream->seek($position);
        }
    }

    /**
     * 过滤并脱敏请求头。
     *
     * @param array<string, mixed> $headers 请求头
     * @param HttpLogOptions $options 日志策略
     * @return array<string, mixed>
     */
    public function sanitizeHeaders(array $headers, HttpLogOptions $options): array
    {
        $allowed = [];

        foreach ($options->allowedHeaders as $header) {
            $allowed[strtolower($header)] = true;
        }

        $result = [];

        foreach ($headers as $name => $value) {
            $headerName = (string) $name;

            if (! isset($allowed[strtolower($headerName)])) {
                continue;
            }

            $result[$headerName] = $this->isSensitiveKey($headerName, $options)
                ? $this->redacted()
                : $this->sanitizeValue($value, $options, $headerName);
        }

        return $result;
    }

    /**
     * 截断过长文本。
     */
    public function truncate(string $value, HttpLogOptions $options): string
    {
        if (mb_strlen($value) <= $options->maxContentLength) {
            return $value;
        }

        return mb_substr($value, 0, $options->maxContentLength) . '...[truncated]';
    }

    /**
     * @param mixed $value query / json / form 值
     * @param HttpLogOptions $options 策略
     * @param string $key 字段名
     */
    private function sanitizeQueryOrBody(mixed $value, HttpLogOptions $options, string $key): mixed
    {
        if (is_string($value)) {
            if ($key === 'query') {
                parse_str($value, $parsed);

                return $this->sanitizeValue($parsed, $options, $key);
            }

            return $this->sanitizeRawBody($value, $options);
        }

        return $this->sanitizeValue($value, $options, $key);
    }

    /**
     * @param mixed $multipart multipart 选项
     * @param HttpLogOptions $options 策略
     */
    private function sanitizeMultipart(mixed $multipart, HttpLogOptions $options): mixed
    {
        if (! is_array($multipart)) {
            return '[multipart omitted]';
        }

        $result = [];

        foreach ($multipart as $index => $part) {
            if (! is_array($part)) {
                $result[$index] = '[multipart part omitted]';
                continue;
            }

            $name = isset($part['name']) ? (string) $part['name'] : '';
            $entry = $part;

            if ($name !== '' && $this->isSensitiveKey($name, $options)) {
                $entry['contents'] = $this->redacted();
            } elseif (array_key_exists('contents', $part)) {
                $contents = $part['contents'];
                if (is_resource($contents) || $contents instanceof StreamInterface) {
                    $entry['contents'] = '[stream omitted]';
                } else {
                    $entry['contents'] = $this->sanitizeValue($contents, $options, $name !== '' ? $name : 'contents');
                }
            }

            if (isset($entry['headers']) && is_array($entry['headers'])) {
                $entry['headers'] = $this->sanitizeHeaders($entry['headers'], $options);
            }

            $result[$index] = $entry;
        }

        return $result;
    }

    /**
     * @param mixed $body 原始 body
     * @param HttpLogOptions $options 策略
     */
    private function sanitizeRawBody(mixed $body, HttpLogOptions $options): mixed
    {
        if (is_resource($body) || $body instanceof StreamInterface) {
            return '[stream omitted]';
        }

        if (! is_string($body)) {
            return $this->sanitizeValue($body, $options, 'body');
        }

        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $sanitized = $this->sanitizeValue($decoded, $options, 'body');

            try {
                return $this->truncate(
                    json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $options,
                );
            } catch (\JsonException) {
                return '[response body omitted: json encode failed]';
            }
        }

        // 无法安全按字段解析时不落原文
        return '[body omitted: non-json]';
    }

    /**
     * @param mixed $value 值
     * @param HttpLogOptions $options 策略
     * @param string|int|null $key 当前字段名
     * @return mixed
     */
    private function sanitizeValue(mixed $value, HttpLogOptions $options, string|int|null $key): mixed
    {
        if (is_string($key) && $this->isSensitiveKey($key, $options)) {
            return $this->redacted();
        }

        if (is_resource($value) || $value instanceof StreamInterface) {
            return '[stream omitted]';
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $childKey => $childValue) {
                if (is_string($childKey) && $this->isSensitiveKey($childKey, $options)) {
                    $result[$childKey] = $this->redacted();
                    continue;
                }

                $result[$childKey] = $this->sanitizeValue($childValue, $options, $childKey);
            }

            return $result;
        }

        if (is_string($value)) {
            return $this->truncate($value, $options);
        }

        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }

        return $value;
    }

    private function isSensitiveKey(string $key, HttpLogOptions $options): bool
    {
        foreach ($this->keyCandidates($key) as $candidate) {
            $normalized = strtolower($candidate);

            foreach ($options->sensitiveKeys as $sensitiveKey) {
                if ($normalized === strtolower($sensitiveKey)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 展开表单嵌套名，使 token[] / user[password] / user.password 与敏感键一致受保护。
     *
     * @return list<string>
     */
    private function keyCandidates(string $key): array
    {
        $candidates = [$key];

        // token[] / token[0] → token
        if (preg_match('/^([^\[]+)/', $key, $prefix) === 1 && $prefix[1] !== $key) {
            $candidates[] = $prefix[1];
        }

        if (preg_match_all('/\[([^\]]*)\]/', $key, $matches) > 0) {
            foreach ($matches[1] as $segment) {
                if ($segment !== '') {
                    $candidates[] = $segment;
                }
            }
        }

        if (str_contains($key, '.')) {
            foreach (explode('.', $key) as $segment) {
                if ($segment !== '') {
                    $candidates[] = $segment;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    private function redacted(): string
    {
        return '***';
    }
}
