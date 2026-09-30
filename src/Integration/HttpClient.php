<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Integration;

use InvalidArgumentException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;
use Valencio\LaravelToolkit\Integration\Logging\HttpLogOptions;
use Valencio\LaravelToolkit\Integration\Logging\IntegrationLogger;

/**
 * 第三方集成 HTTP 客户端基类。
 *
 * 固定发送、HTTP 成功检查与日志流程。具体客户端继承后配置请求、按需校验业务响应，
 * 并向外暴露自己的能力方法。send() 返回 IntegrationResult，默认不断开调用栈；
 * 需要硬失败时使用 *OrFail() 或 Result::valueOrThrow()。
 */
abstract class HttpClient
{
    /**
     * 服务配置根键，如 extend.tianapi。
     *
     * 构造时一次性载入到 $settings；`{key}.logging` 自动并入日志策略。
     */
    protected ?string $serviceConfigKey = null;

    /**
     * 本服务配置（构造时载入一次，方法内勿再读 Config）。
     *
     * @var array<string, mixed>
     */
    protected array $settings = [];

    /**
     * @param Factory $http Laravel HTTP 工厂
     * @param IntegrationLogger $logger 集成日志器
     * @param ConfigRepository $config 配置仓库
     */
    public function __construct(
        protected readonly Factory $http,
        protected readonly IntegrationLogger $logger,
        protected readonly ConfigRepository $config,
    ) {
        $this->settings = $this->loadSettings();
    }

    /**
     * 配置本次请求（baseUrl、认证、默认 query/header 等）。
     *
     * 公共密钥、基址写在这里；具体能力方法只传业务参数，不再读配置。
     *
     * @param PendingRequest $request 新建请求对象
     * @return PendingRequest 配置后的请求对象
     */
    abstract protected function configureRequest(PendingRequest $request): PendingRequest;

    /**
     * 按第三方规则检查业务状态及响应结构。
     *
     * HTTP 2xx 检查由 send() 固定执行，子类无需也不应替代。
     * 校验失败应抛出 IntegrationException，由 send() 转为 Result::failure。
     *
     * @param Response $response HTTP 响应
     * @param string|null $operation 操作标识
     *
     * @throws IntegrationException 当响应不满足约定时
     */
    protected function validateResponse(Response $response, ?string $operation): void
    {
    }

    /**
     * 返回本客户端的日志策略。
     *
     * 包默认作兜底，并合并构造时载入的 `$settings['logging']`。
     */
    protected function logOptions(): HttpLogOptions
    {
        /** @var array<string, mixed> $defaults */
        $defaults = $this->config->get('laravel-toolkit.integration.logging', []);
        $override = $this->settings['logging'] ?? [];

        if (! is_array($override)) {
            $override = [];
        }

        return HttpLogOptions::fromConfig([...$defaults, ...$override]);
    }

    /**
     * 从 serviceConfigKey 一次性载入服务配置。
     *
     * @return array<string, mixed>
     */
    private function loadSettings(): array
    {
        $root = $this->serviceConfigKey;

        if ($root === null || $root === '') {
            return [];
        }

        $settings = $this->config->get($root, []);

        return is_array($settings) ? $settings : [];
    }

    /**
     * 公共发送入口：返回 Result，失败不抛异常。
     *
     * @param string $method HTTP 方法
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 代码内固定操作标识
     * @return IntegrationResult
     */
    final protected function send(
        string $method,
        string $uri,
        array $options = [],
        ?string $operation = null,
    ): IntegrationResult {
        $startedAt = hrtime(true);
        $logOptions = $this->logOptions();
        $pending = $this->newPendingRequest();
        $response = null;

        /** @var array{uri: string, options: array<string, mixed>} $snapshot */
        $snapshot = $this->pendingSnapshot($pending, $uri, $options);

        $pending = $pending->beforeSending(function (Request $request, array $guzzleOptions) use (&$snapshot): void {
            $outgoing = $this->snapshotFromOutgoingRequest($request, $guzzleOptions);
            $mergedOptions = $this->mergeRequestOptionBags($snapshot['options'], $outgoing['options']);
            $snapshot = [
                'uri' => $this->buildUriForLog($outgoing['uri'], $mergedOptions),
                'options' => $mergedOptions,
            ];
        });

        try {
            $response = $pending->send($method, $uri, $options);
            $this->assertSuccessfulHttp($response);
            $this->validateResponse($response, $operation);
        } catch (ConnectionException $exception) {
            return $this->failureResult(
                error: new IntegrationException(
                    message: 'Integration HTTP connection failed.',
                    failureType: FailureType::Connection,
                    previous: $exception,
                ),
                logOptions: $logOptions,
                method: $method,
                snapshot: $snapshot,
                operation: $operation,
                response: null,
                startedAt: $startedAt,
                exceptionClass: $exception::class,
            );
        } catch (RequestException $exception) {
            $response = $exception->response;

            return $this->failureResult(
                error: new IntegrationException(
                    message: 'Integration HTTP request failed.',
                    failureType: FailureType::Http,
                    httpStatus: $response?->status(),
                    previous: $exception,
                ),
                logOptions: $logOptions,
                method: $method,
                snapshot: $snapshot,
                operation: $operation,
                response: $response,
                startedAt: $startedAt,
                exceptionClass: $exception::class,
            );
        } catch (IntegrationException $exception) {
            return $this->failureResult(
                error: $exception,
                logOptions: $logOptions,
                method: $method,
                snapshot: $snapshot,
                operation: $operation,
                response: $response,
                startedAt: $startedAt,
                exceptionClass: $exception::class,
            );
        }

        $this->safeLog(
            logOptions: $logOptions,
            method: $method,
            uri: $snapshot['uri'],
            operation: $operation,
            requestOptions: $snapshot['options'],
            response: $response,
            isFailure: false,
            durationMs: $this->durationMs($startedAt),
        );

        return IntegrationResult::success($response);
    }

    /**
     * 硬失败发送：失败时抛出 IntegrationException。
     *
     * @param string $method HTTP 方法
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     * @return Response 成功时的响应
     *
     * @throws IntegrationException
     */
    final protected function sendOrFail(
        string $method,
        string $uri,
        array $options = [],
        ?string $operation = null,
    ): Response {
        return $this->send($method, $uri, $options, $operation)->valueOrThrow();
    }

    /**
     * GET：返回 Result。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     */
    final protected function get(string $uri, array $options = [], ?string $operation = null): IntegrationResult
    {
        return $this->send('GET', $uri, $options, $operation);
    }

    /**
     * GET：失败抛异常。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     * @return Response
     *
     * @throws IntegrationException
     */
    final protected function getOrFail(string $uri, array $options = [], ?string $operation = null): Response
    {
        return $this->sendOrFail('GET', $uri, $options, $operation);
    }

    /**
     * POST：返回 Result。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     */
    final protected function post(string $uri, array $options = [], ?string $operation = null): IntegrationResult
    {
        return $this->send('POST', $uri, $options, $operation);
    }

    /**
     * POST：失败抛异常。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     * @return Response
     *
     * @throws IntegrationException
     */
    final protected function postOrFail(string $uri, array $options = [], ?string $operation = null): Response
    {
        return $this->sendOrFail('POST', $uri, $options, $operation);
    }

    /**
     * PUT：返回 Result。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     */
    final protected function put(string $uri, array $options = [], ?string $operation = null): IntegrationResult
    {
        return $this->send('PUT', $uri, $options, $operation);
    }

    /**
     * PUT：失败抛异常。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     * @return Response
     *
     * @throws IntegrationException
     */
    final protected function putOrFail(string $uri, array $options = [], ?string $operation = null): Response
    {
        return $this->sendOrFail('PUT', $uri, $options, $operation);
    }

    /**
     * PATCH：返回 Result。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     */
    final protected function patch(string $uri, array $options = [], ?string $operation = null): IntegrationResult
    {
        return $this->send('PATCH', $uri, $options, $operation);
    }

    /**
     * PATCH：失败抛异常。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     * @return Response
     *
     * @throws IntegrationException
     */
    final protected function patchOrFail(string $uri, array $options = [], ?string $operation = null): Response
    {
        return $this->sendOrFail('PATCH', $uri, $options, $operation);
    }

    /**
     * DELETE：返回 Result。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     */
    final protected function delete(string $uri, array $options = [], ?string $operation = null): IntegrationResult
    {
        return $this->send('DELETE', $uri, $options, $operation);
    }

    /**
     * DELETE：失败抛异常。
     *
     * @param string $uri 请求 URI
     * @param array<string, mixed> $options Laravel 原生请求选项
     * @param string|null $operation 操作标识
     * @return Response
     *
     * @throws IntegrationException
     */
    final protected function deleteOrFail(string $uri, array $options = [], ?string $operation = null): Response
    {
        return $this->sendOrFail('DELETE', $uri, $options, $operation);
    }

    /**
     * 固定检查 HTTP 成功范围（2xx）；不可被子类绕过。
     *
     * @throws IntegrationException
     */
    private function assertSuccessfulHttp(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new IntegrationException(
            message: 'Integration HTTP request failed.',
            failureType: FailureType::Http,
            httpStatus: $response->status(),
        );
    }

    /**
     * @param IntegrationException $error 失败原因
     * @param HttpLogOptions $logOptions 日志策略
     * @param string $method HTTP 方法
     * @param array{uri: string, options: array<string, mixed>} $snapshot 最终请求快照
     * @param string|null $operation 操作标识
     * @param Response|null $response 响应
     * @param int $startedAt 起点
     * @param string|null $exceptionClass 异常类名
     */
    private function failureResult(
        IntegrationException $error,
        HttpLogOptions $logOptions,
        string $method,
        array $snapshot,
        ?string $operation,
        ?Response $response,
        int $startedAt,
        ?string $exceptionClass,
    ): IntegrationResult {
        $this->safeLog(
            logOptions: $logOptions,
            method: $method,
            uri: $snapshot['uri'],
            operation: $operation,
            requestOptions: $snapshot['options'],
            response: $response,
            isFailure: true,
            failureType: $error->failureType,
            providerCode: $error->providerCode,
            durationMs: $this->durationMs($startedAt),
            exceptionClass: $exceptionClass ?? $error->previousClass,
        );

        return IntegrationResult::failure($error, $response);
    }

    /**
     * 发送前基于 PendingRequest 已配置选项的基线快照（连接失败时 beforeSending 也可能已更新）。
     *
     * @param array<string, mixed> $sendOptions
     * @return array{uri: string, options: array<string, mixed>}
     */
    private function pendingSnapshot(PendingRequest $pending, string $uri, array $sendOptions): array
    {
        $pendingOptions = $pending->getOptions();
        $merged = $this->mergeRequestOptionBags($pendingOptions, $sendOptions);

        $uriParts = parse_url($uri);
        if (is_array($uriParts) && isset($uriParts['query'])) {
            parse_str($uriParts['query'], $fromUri);
            $merged['query'] = array_replace(
                is_array($merged['query'] ?? null) ? $merged['query'] : [],
                $fromUri,
            );
        }

        return [
            'uri' => $this->buildUriForLog($uri, $merged),
            'options' => $merged,
        ];
    }

    /**
     * 从即将发出的请求捕获完整快照（含 configureRequest 合并后的头、query、body）。
     *
     * @param array<string, mixed> $guzzleOptions
     * @return array{uri: string, options: array<string, mixed>}
     */
    private function snapshotFromOutgoingRequest(Request $request, array $guzzleOptions): array
    {
        $options = [];

        $headers = $request->headers();
        if ($headers !== []) {
            $normalized = [];
            foreach ($headers as $name => $value) {
                $normalized[$name] = is_array($value) ? (string) ($value[0] ?? '') : $value;
            }
            $options['headers'] = $normalized;
        }

        $url = $request->url();
        $parts = parse_url($url);
        $query = [];
        if (is_array($parts) && isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        if (isset($guzzleOptions['query'])) {
            $fromOptions = $guzzleOptions['query'];
            if (is_string($fromOptions)) {
                parse_str($fromOptions, $parsed);
                $fromOptions = $parsed;
            }
            if (is_array($fromOptions)) {
                $query = array_replace($query, $fromOptions);
            }
        }

        if ($query !== []) {
            $options['query'] = $query;
        }

        if (isset($guzzleOptions['json'])) {
            $options['json'] = $guzzleOptions['json'];
        } elseif (isset($guzzleOptions['form_params'])) {
            $options['form_params'] = $guzzleOptions['form_params'];
        } elseif (isset($guzzleOptions['multipart'])) {
            $options['multipart'] = $guzzleOptions['multipart'];
        } elseif (isset($guzzleOptions['body'])) {
            $options['body'] = $guzzleOptions['body'];
        } else {
            $data = $request->data();
            if ($data !== []) {
                $options['json'] = $data;
            }
        }

        return [
            'uri' => $url,
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function mergeRequestOptionBags(array $left, array $right): array
    {
        $merged = $left;

        foreach ($right as $key => $value) {
            if ($key === 'query' || $key === 'headers') {
                $existing = $merged[$key] ?? [];
                if (is_string($existing)) {
                    parse_str($existing, $parsed);
                    $existing = $parsed;
                }
                if (is_string($value)) {
                    parse_str($value, $parsedValue);
                    $value = $parsedValue;
                }
                $merged[$key] = array_replace(
                    is_array($existing) ? $existing : [],
                    is_array($value) ? $value : [],
                );
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildUriForLog(string $uri, array $options): string
    {
        $parts = parse_url($uri);
        if ($parts === false) {
            return $uri;
        }

        $base = '';
        if (isset($parts['scheme'])) {
            $base .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $base .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        $base .= $parts['path'] ?? $uri;

        $query = is_array($options['query'] ?? null) ? $options['query'] : [];
        if ($query !== []) {
            $base .= '?' . http_build_query($query);
        }

        return $base;
    }

    /**
     * 创建带默认超时、并经子类配置后的独立 PendingRequest。
     */
    private function newPendingRequest(): PendingRequest
    {
        $pending = $this->http
            ->connectTimeout($this->floatConfig(
                'laravel-toolkit.integration.http.connect_timeout',
                3.0,
            ))
            ->timeout($this->floatConfig(
                'laravel-toolkit.integration.http.timeout',
                10.0,
            ));

        return $this->configureRequest($pending);
    }

    /**
     * @param string $key 配置键
     * @param float $default 默认值
     */
    private function floatConfig(string $key, float $default): float
    {
        $value = $this->config->get($key, $default);

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Config [{$key}] must be numeric.");
        }

        $float = (float) $value;

        if (! is_finite($float) || $float <= 0) {
            throw new InvalidArgumentException(
                "Config [{$key}] must be a finite positive number.",
            );
        }

        return $float;
    }

    /**
     * @param int $startedAt hrtime(true) 起点
     */
    private function durationMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * @param array<string, mixed> $requestOptions
     */
    private function safeLog(
        HttpLogOptions $logOptions,
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
        try {
            $this->logger->log(
                options: $logOptions,
                method: $method,
                uri: $uri,
                operation: $operation,
                requestOptions: $requestOptions,
                response: $response,
                isFailure: $isFailure,
                failureType: $failureType,
                providerCode: $providerCode,
                durationMs: $durationMs,
                exceptionClass: $exceptionClass,
            );
        } catch (Throwable $loggingError) {
            error_log(sprintf(
                '[laravel-toolkit] integration log failed: %s',
                $loggingError::class,
            ));
        }
    }
}
