<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Integration;

use Illuminate\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Valencio\LaravelToolkit\Integration\FailureType;
use Valencio\LaravelToolkit\Integration\HttpClient;
use Valencio\LaravelToolkit\Integration\IntegrationException;
use Valencio\LaravelToolkit\Integration\IntegrationResult;
use Valencio\LaravelToolkit\Integration\Logging\HttpLogOptions;
use Valencio\LaravelToolkit\Integration\Logging\IntegrationLogger;
use Valencio\LaravelToolkit\Integration\Logging\LogContentMode;
use Valencio\LaravelToolkit\Integration\Logging\LogSanitizer;

/**
 * Integration HttpClient 验收测试。
 */
final class HttpClientTest extends TestCase
{
    /**
     * @var array<string, list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}>>
     */
    private array $channelRecords = [];

    /**
     * 成功应返回 successful Result，默认不写成功日志。
     */
    public function test_send_returns_successful_result_without_success_log_by_default(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['code' => 0, 'data' => ['id' => 1]], 200),
        ]);

        $client = $this->makeClient($http);
        $result = $client->fetchItems(['page' => 1]);

        $this->assertTrue($result->successful());
        $this->assertSame(200, $result->response()?->status());
        $this->assertSame(1, $result->response()?->json('data.id'));
        $this->assertSame([], $this->channelRecords);
    }

    /**
     * HTTP 失败应返回 Failure Result，并只记一次失败日志；默认不抛。
     */
    public function test_send_returns_http_failure_result_and_logs_once(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['message' => 'gone'], 503),
        ]);

        $client = $this->makeClient($http);
        $result = $client->fetchItems();

        $this->assertTrue($result->failed());
        $this->assertSame(FailureType::Http, $result->error()?->failureType);
        $this->assertSame(503, $result->error()?->httpStatus);
        $this->assertSame(503, $result->response()?->status());
        $this->assertSame([], $result->valueOr([]));

        $this->assertCount(1, $this->channelRecords['default'] ?? []);
        $this->assertSame('error', $this->channelRecords['default'][0]['level']);
        $this->assertSame('http', $this->channelRecords['default'][0]['context']['failure_type']);
    }

    /**
     * 硬失败入口应抛出 IntegrationException。
     */
    public function test_send_or_fail_throws_on_http_failure(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['message' => 'gone'], 503),
        ]);

        $client = $this->makeClient($http);

        try {
            $client->fetchItemsOrFail();
            $this->fail('Expected IntegrationException was not thrown.');
        } catch (IntegrationException $exception) {
            $this->assertSame(FailureType::Http, $exception->failureType);
            $this->assertSame(503, $exception->httpStatus);
        }

        $this->assertCount(1, $this->channelRecords['default'] ?? []);
    }

    /**
     * 业务失败由子类校验，进入 Failure Result。
     */
    public function test_send_returns_business_failure_result(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['code' => 1001, 'message' => 'quota'], 200),
        ]);

        $client = $this->makeClient($http, validateBusiness: true);
        $result = $client->fetchItems();

        $this->assertTrue($result->failed());
        $this->assertSame(FailureType::Business, $result->error()?->failureType);
        $this->assertSame(200, $result->error()?->httpStatus);
        $this->assertSame(1001, $result->error()?->providerCode);
        $this->assertSame('business', $this->channelRecords['default'][0]['context']['failure_type']);
    }

    /**
     * 非法响应结构进入 InvalidResponse Failure。
     */
    public function test_send_returns_invalid_response_failure_result(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response('not-json', 200),
        ]);

        $client = $this->makeClient($http, requireJsonObject: true);
        $result = $client->fetchItems();

        $this->assertTrue($result->failed());
        $this->assertSame(FailureType::InvalidResponse, $result->error()?->failureType);
        $this->assertCount(1, $this->channelRecords['default'] ?? []);
    }

    /**
     * 连接异常进入 Connection Failure，并可 valueOrThrow。
     */
    public function test_send_returns_connection_failure_and_value_or_throw_rethrows(): void
    {
        $http = new Factory;
        $http->fake(function (): never {
            throw new ConnectionException('cURL error 7: Failed to connect to https://secret.test?key=abc');
        });

        $client = $this->makeClient($http);
        $result = $client->fetchItems();

        $this->assertTrue($result->failed());
        $this->assertSame(FailureType::Connection, $result->error()?->failureType);
        $this->assertNull($result->response());

        try {
            $result->valueOrThrow();
            $this->fail('Expected IntegrationException was not thrown.');
        } catch (IntegrationException $exception) {
            $this->assertSame(FailureType::Connection, $exception->failureType);
            $this->assertNull($exception->getPrevious());
            $this->assertSame(ConnectionException::class, $exception->previousClass);
            $this->assertStringNotContainsString('secret.test', $exception->getMessage());
        }

        $this->assertCount(1, $this->channelRecords['default'] ?? []);
        $encoded = json_encode($this->channelRecords['default'][0], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('key=abc', $encoded);
    }

    /**
     * 子类未做 HTTP 检查时，基类仍拒绝非 2xx。
     */
    public function test_http_status_check_cannot_be_bypassed_by_subclass(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['ok' => false], 500),
        ]);

        $client = $this->makeClient($http, skipHttpInValidate: true);
        $result = $client->fetchItems();

        $this->assertTrue($result->failed());
        $this->assertSame(FailureType::Http, $result->error()?->failureType);
    }

    /**
     * URI query 与响应 JSON 中的敏感字段应脱敏；业务码写入日志。
     */
    public function test_logs_sanitize_uri_response_and_include_provider_code(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response([
                'code' => 150,
                'token' => 'leak-token',
                'msg' => 'quota',
            ], 200),
        ]);

        $client = $this->makeClient(
            $http,
            validateBusiness: true,
            logOptions: HttpLogOptions::fromConfig([])->with(
                requestBody: LogContentMode::Always,
                responseBody: LogContentMode::Always,
            ),
        );

        $result = $client->getWithSecretQuery();

        $this->assertTrue($result->failed());
        $context = $this->channelRecords['default'][0]['context'];
        $this->assertSame(150, $context['provider_code']);
        $this->assertStringContainsString('key=%2A%2A%2A', $context['uri']);
        $this->assertStringNotContainsString('super-secret', $context['uri']);
        $this->assertStringNotContainsString('leak-token', json_encode($context, JSON_THROW_ON_ERROR));
        $this->assertArrayHasKey('query', $context['request']);
        $this->assertSame('***', $context['request']['query']['key']);
        $this->assertSame('cn', $context['request']['query']['region']);
    }

    /**
     * multipart 按 name 脱敏 contents。
     */
    public function test_multipart_password_contents_are_redacted(): void
    {
        $sanitizer = new LogSanitizer;
        $options = HttpLogOptions::fromConfig([]);

        $sanitized = $sanitizer->sanitizeRequestOptions([
            'multipart' => [
                [
                    'name' => 'password',
                    'contents' => 'plain-password',
                ],
            ],
        ], $options);

        $this->assertSame('***', $sanitized['multipart'][0]['contents']);
    }

    /**
     * 记录响应日志后，调用方仍可读 body，且校验读过 json 后日志仍有正文。
     */
    public function test_logging_response_does_not_consume_caller_body(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['token' => 'x', 'ok' => true, 'code' => 0], 200),
        ]);

        $client = $this->makeClient(
            $http,
            validateBusiness: true,
            logOptions: HttpLogOptions::fromConfig([])->with(
                logSuccess: true,
                responseBody: LogContentMode::Always,
            ),
        );

        $result = $client->fetchItems();
        $this->assertTrue($result->successful());
        $this->assertSame(true, $result->response()?->json('ok'));
        $this->assertSame('x', $result->response()?->json('token'));

        $responseLog = $this->channelRecords['default'][0]['context']['response'] ?? null;
        $this->assertIsString($responseLog);
        $this->assertNotSame('', $responseLog);
        $this->assertStringContainsString('"ok":true', $responseLog);
        $this->assertStringContainsString('"token":"***"', $responseLog);
        $this->assertStringNotContainsString('"token":"x"', $responseLog);
    }

    /**
     * multipart 嵌套名 user[password] 应脱敏。
     */
    public function test_multipart_nested_password_name_is_redacted(): void
    {
        $sanitizer = new LogSanitizer;
        $options = HttpLogOptions::fromConfig([]);

        $sanitized = $sanitizer->sanitizeRequestOptions([
            'multipart' => [
                [
                    'name' => 'user[password]',
                    'contents' => 'plain-password',
                ],
            ],
        ], $options);

        $this->assertSame('***', $sanitized['multipart'][0]['contents']);
    }

    /**
     * multipart 的 token[] / token[0] 应按基名 token 脱敏。
     */
    public function test_multipart_token_array_name_is_redacted(): void
    {
        $sanitizer = new LogSanitizer;
        $options = HttpLogOptions::fromConfig([]);

        $sanitized = $sanitizer->sanitizeRequestOptions([
            'multipart' => [
                [
                    'name' => 'token[]',
                    'contents' => 'secret-token-a',
                ],
                [
                    'name' => 'token[0]',
                    'contents' => 'secret-token-b',
                ],
                [
                    'name' => 'file[]',
                    'contents' => 'plain-file',
                ],
            ],
        ], $options);

        $this->assertSame('***', $sanitized['multipart'][0]['contents']);
        $this->assertSame('***', $sanitized['multipart'][1]['contents']);
        $this->assertSame('plain-file', $sanitized['multipart'][2]['contents']);
    }

    /**
     * sensitive_keys 配成非数组应直接失败。
     */
    public function test_sensitive_keys_string_config_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HttpLogOptions::fromConfig([
            'sensitive_keys' => 'token',
        ]);
    }

    /**
     * 超时必须是有限正数。
     */
    public function test_non_positive_timeout_config_is_rejected(): void
    {
        $http = new Factory;
        $config = new Repository([
            'laravel-toolkit' => [
                'integration' => [
                    'http' => [
                        'connect_timeout' => 0,
                        'timeout' => 10,
                    ],
                    'logging' => [],
                ],
            ],
        ]);

        $logger = new IntegrationLogger(
            channelResolver: fn (?string $channel): LoggerInterface => $this->makeChannelLogger($channel ?: 'default'),
            sanitizer: new LogSanitizer,
        );

        $this->expectException(\InvalidArgumentException::class);

        $client = new TestIntegrationClient($http, $logger, $config);
        $client->fetchItems();
    }

    /**
     * configureRequest 注入的公共参数在连接失败时也应进日志。
     */
    public function test_connection_failure_logs_configure_request_query(): void
    {
        $http = new Factory;
        $http->fake(function (): never {
            throw new ConnectionException('cURL error 7: Failed to connect');
        });

        $client = $this->makeClient(
            $http,
            logOptions: HttpLogOptions::fromConfig([])->with(
                requestBody: LogContentMode::Always,
            ),
        );

        $result = $client->fetchItems();
        $this->assertTrue($result->failed());
        $this->assertSame('cn', $this->channelRecords['default'][0]['context']['request']['query']['region']);
    }

    /**
     * 日志写入失败不得覆盖集成 Result。
     */
    public function test_logging_failure_does_not_override_integration_result(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['ok' => true], 500),
        ]);

        $config = new Repository([
            'laravel-toolkit' => require dirname(__DIR__, 2) . '/config/laravel-toolkit.php',
        ]);

        $logger = new IntegrationLogger(
            channelResolver: static function (?string $channel): LoggerInterface {
                return new class extends AbstractLogger {
                    public function log($level, string|\Stringable $message, array $context = []): void
                    {
                        throw new \RuntimeException('log sink exploded');
                    }
                };
            },
            sanitizer: new LogSanitizer,
        );

        $client = new TestIntegrationClient(
            http: $http,
            logger: $logger,
            config: $config,
        );

        $result = $client->fetchItems();

        $this->assertTrue($result->failed());
        $this->assertSame(FailureType::Http, $result->error()?->failureType);
    }

    /**
     * sensitive_keys_extra 追加而不替换默认保护。
     */
    public function test_sensitive_keys_extra_appends_defaults(): void
    {
        $options = HttpLogOptions::fromConfig([
            'sensitive_keys_extra' => ['phone'],
        ]);

        $sanitizer = new LogSanitizer;
        $sanitized = $sanitizer->sanitize([
            'token' => 't',
            'phone' => '13800000000',
            'name' => 'a',
        ], $options);

        $this->assertSame('***', $sanitized['token']);
        $this->assertSame('***', $sanitized['phone']);
        $this->assertSame('a', $sanitized['name']);
    }

    /**
     * 客户端可指定自定义日志通道。
     */
    public function test_send_uses_custom_log_channel(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['message' => 'no'], 500),
        ]);

        $client = $this->makeClient(
            $http,
            logOptions: HttpLogOptions::fromConfig([])->with(channel: 'integrations'),
        );

        $client->fetchItems();

        $this->assertArrayHasKey('integrations', $this->channelRecords);
        $this->assertCount(1, $this->channelRecords['integrations']);
        $this->assertArrayNotHasKey('default', $this->channelRecords);
    }

    /**
     * 声明 serviceConfigKey 后自动合并服务 logging 配置。
     */
    public function test_service_config_key_merges_logging_options(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['ok' => true], 200),
        ]);

        $client = $this->makeClient(
            $http,
            serviceConfigKey: 'extend.demo',
            extraConfig: [
                'extend' => [
                    'demo' => [
                        'logging' => [
                            'channel' => 'demo',
                            'log_success' => true,
                            'request_body' => 'always',
                            'response_body' => 'always',
                        ],
                    ],
                ],
            ],
        );

        $client->fetchItems();

        $this->assertArrayHasKey('demo', $this->channelRecords);
        $this->assertCount(1, $this->channelRecords['demo']);
        $this->assertSame('info', $this->channelRecords['demo'][0]['level']);
        $this->assertArrayHasKey('response', $this->channelRecords['demo'][0]['context']);
        $this->assertArrayNotHasKey('default', $this->channelRecords);
    }

    /**
     * 嵌套敏感字段应被脱敏后写入日志。
     */
    public function test_failure_log_sanitizes_nested_sensitive_fields(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/*' => Factory::response(['error' => 'denied'], 401),
        ]);

        $client = $this->makeClient($http);
        $client->createUser([
            'name' => 'alice',
            'password' => 'plain-password',
            'profile' => [
                'token' => 'nested-token',
                'city' => 'Shanghai',
            ],
        ]);

        $request = $this->channelRecords['default'][0]['context']['request'];
        $this->assertSame('alice', $request['json']['name']);
        $this->assertSame('***', $request['json']['password']);
        $this->assertSame('***', $request['json']['profile']['token']);
        $this->assertSame('Shanghai', $request['json']['profile']['city']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertArrayNotHasKey('Authorization', $request['headers']);
        $this->assertArrayNotHasKey('X-Debug', $request['headers']);

        $encoded = json_encode($this->channelRecords['default'][0], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('plain-password', $encoded);
        $this->assertStringNotContainsString('nested-token', $encoded);
        $this->assertStringNotContainsString('secret-token', $encoded);
    }

    /**
     * 成功日志开关打开时才记录成功；失败始终记录。
     */
    public function test_log_success_switch_and_failure_always_logged(): void
    {
        $http = new Factory;
        $http->fake([
            'https://example.test/items*' => Factory::response(['code' => 0], 200),
            'https://example.test/fail*' => Factory::response(['error' => true], 500),
        ]);

        $client = $this->makeClient(
            $http,
            logOptions: HttpLogOptions::fromConfig([])->with(
                logSuccess: true,
                requestBody: LogContentMode::Always,
                responseBody: LogContentMode::Always,
            ),
        );

        $client->fetchItems();
        $this->assertCount(1, $this->channelRecords['default']);
        $this->assertSame('info', $this->channelRecords['default'][0]['level']);

        $client->fetchBroken();
        $this->assertCount(2, $this->channelRecords['default']);
        $this->assertSame('error', $this->channelRecords['default'][1]['level']);
    }

    /**
     * 连续请求不得串扰状态；每次返回各自结果。
     */
    public function test_consecutive_requests_do_not_share_state(): void
    {
        $http = new Factory;
        $http->fake([
            '*' => $http->sequence()
                ->push(['page' => 1], 200)
                ->push(['page' => 2], 200),
        ]);

        $client = $this->makeClient($http);

        $first = $client->fetchItems();
        $second = $client->fetchItems();

        $this->assertSame(1, $first->response()?->json('page'));
        $this->assertSame(2, $second->response()?->json('page'));
    }

    /**
     * 脱敏器不得读取流内容。
     */
    public function test_log_sanitizer_omits_streams_without_consuming_them(): void
    {
        $sanitizer = new LogSanitizer;
        $options = HttpLogOptions::fromConfig([]);
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, 'secret-stream-content');
        rewind($stream);

        $sanitized = $sanitizer->sanitizeRequestOptions([
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => $stream,
                ],
            ],
        ], $options);

        $this->assertSame('[stream omitted]', $sanitized['multipart'][0]['contents']);
        $this->assertSame('secret-stream-content', stream_get_contents($stream));
        fclose($stream);
    }

    /**
     * @param Factory $http HTTP 工厂
     * @param bool $validateBusiness 是否校验业务码
     * @param bool $requireJsonObject 是否要求 JSON 对象
     * @param HttpLogOptions|null $logOptions 自定义日志策略
     * @param string|null $serviceConfigKey 服务配置根键
     * @param array<string, mixed> $extraConfig 额外配置
     * @param bool $skipHttpInValidate 子类 validate 故意不做 HTTP 检查（验证基类钉死）
     */
    private function makeClient(
        Factory $http,
        bool $validateBusiness = false,
        bool $requireJsonObject = false,
        ?HttpLogOptions $logOptions = null,
        ?string $serviceConfigKey = null,
        array $extraConfig = [],
        bool $skipHttpInValidate = false,
    ): TestIntegrationClient {
        $config = new Repository(array_replace_recursive([
            'laravel-toolkit' => require dirname(__DIR__, 2) . '/config/laravel-toolkit.php',
        ], $extraConfig));

        $logger = new IntegrationLogger(
            channelResolver: function (?string $channel): LoggerInterface {
                $name = $channel ?: 'default';

                return $this->makeChannelLogger($name);
            },
            sanitizer: new LogSanitizer,
        );

        return new TestIntegrationClient(
            http: $http,
            logger: $logger,
            config: $config,
            validateBusiness: $validateBusiness,
            requireJsonObject: $requireJsonObject,
            customLogOptions: $logOptions,
            serviceConfigKey: $serviceConfigKey,
            skipHttpInValidate: $skipHttpInValidate,
        );
    }

    /**
     * @param string $channel 通道名
     */
    private function makeChannelLogger(string $channel): AbstractLogger
    {
        $records = &$this->channelRecords;

        return new class($channel, $records) extends AbstractLogger {
            /**
             * @param array<string, list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}>> $records
             */
            public function __construct(
                private string $channel,
                private array &$records,
            ) {
            }

            /**
             * @param mixed $level
             * @param array<string, mixed> $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[$this->channel][] = [
                    'level' => $level,
                    'message' => $message,
                    'context' => $context,
                ];
            }
        };
    }
}

/**
 * 测试用集成客户端。
 */
final class TestIntegrationClient extends HttpClient
{
    /**
     * @param Factory $http HTTP 工厂
     * @param IntegrationLogger $logger 日志器
     * @param Repository $config 配置
     * @param bool $validateBusiness 是否校验业务码
     * @param bool $requireJsonObject 是否要求 JSON 对象
     * @param HttpLogOptions|null $customLogOptions 自定义日志策略
     * @param string|null $serviceConfigKey 服务配置根键
     * @param bool $skipHttpInValidate 子类不检查 HTTP（基类仍应拒绝）
     */
    public function __construct(
        Factory $http,
        IntegrationLogger $logger,
        Repository $config,
        private readonly bool $validateBusiness = false,
        private readonly bool $requireJsonObject = false,
        private readonly ?HttpLogOptions $customLogOptions = null,
        ?string $serviceConfigKey = null,
        private readonly bool $skipHttpInValidate = false,
    ) {
        $this->serviceConfigKey = $serviceConfigKey;
        parent::__construct($http, $logger, $config);
    }

    /**
     * @param array<string, mixed> $query 查询参数
     */
    public function fetchItems(array $query = []): IntegrationResult
    {
        return $this->get('/items', [
            'query' => $query,
        ], 'fetch_items');
    }

    /**
     * 故意把密钥放进 URI query，验证日志脱敏。
     */
    public function getWithSecretQuery(): IntegrationResult
    {
        return $this->get('/items?key=super-secret', [], 'secret_query');
    }

    /**
     * @param array<string, mixed> $query 查询参数
     * @throws IntegrationException
     */
    public function fetchItemsOrFail(array $query = []): Response
    {
        return $this->getOrFail('/items', [
            'query' => $query,
        ], 'fetch_items');
    }

    /**
     * 触发 HTTP 失败的接口。
     */
    public function fetchBroken(): IntegrationResult
    {
        return $this->get('/fail', [], 'fetch_broken');
    }

    /**
     * @param array<string, mixed> $payload 请求体
     */
    public function createUser(array $payload): IntegrationResult
    {
        return $this->post('/users', [
            'json' => $payload,
            'headers' => [
                'Authorization' => 'Bearer secret-token',
                'Content-Type' => 'application/json',
                'X-Debug' => '1',
            ],
        ], 'create_user');
    }

    protected function configureRequest(PendingRequest $request): PendingRequest
    {
        return $request
            ->baseUrl('https://example.test')
            ->withQueryParameters([
                'region' => 'cn',
            ])
            ->acceptJson();
    }

    protected function validateResponse(Response $response, ?string $operation): void
    {
        // 故意不检查 HTTP，验证基类 assertSuccessfulHttp。
        if ($this->skipHttpInValidate) {
            return;
        }

        parent::validateResponse($response, $operation);

        if ($this->requireJsonObject) {
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new IntegrationException(
                    message: 'Integration response format is invalid.',
                    failureType: FailureType::InvalidResponse,
                    httpStatus: $response->status(),
                );
            }
        }

        if ($this->validateBusiness) {
            $code = $response->json('code');

            if ($code !== 0) {
                throw new IntegrationException(
                    message: 'Integration business request failed.',
                    failureType: FailureType::Business,
                    httpStatus: $response->status(),
                    providerCode: is_int($code) || is_string($code) ? $code : null,
                );
            }
        }
    }

    protected function logOptions(): HttpLogOptions
    {
        return $this->customLogOptions ?? parent::logOptions();
    }
}
