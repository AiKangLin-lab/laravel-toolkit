# Laravel Toolkit

Laravel 13 可复用工具包。当前提供第三方集成 HTTP 客户端基类：固定发送、HTTP 校验与安全日志流程，具体服务继承后只写自己的配置和业务方法。

## Integration HTTP

```php
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Valencio\LaravelToolkit\Integration\HttpClient;
use Valencio\LaravelToolkit\Integration\IntegrationResult;

final class ExampleClient extends HttpClient
{
    protected ?string $serviceConfigKey = 'services.example';

    private string $baseUrl;
    private string $token;

    public function __construct(...)
    {
        parent::__construct(...);
        $this->baseUrl = (string) ($this->settings['base_url'] ?? '');
        $this->token = (string) ($this->settings['token'] ?? '');
    }

    protected function configureRequest(PendingRequest $request): PendingRequest
    {
        return $request
            ->baseUrl($this->baseUrl)
            ->withToken($this->token);
    }

    /** 默认软失败：返回 Result，不中断调用栈 */
    public function fetchItems(array $query = []): IntegrationResult
    {
        return $this->get('/items', [
            'query' => $query,
        ], 'fetch_items');
    }

    /** 硬失败：失败直接抛 IntegrationException */
    public function fetchItemsOrFail(array $query = []): Response
    {
        return $this->getOrFail('/items', [
            'query' => $query,
        ], 'fetch_items');
    }
}
```

调用方决定策略：

```php
$result = $client->fetchItems(['page' => 1]);

// 软失败：拿不到就空数组，接口仍可继续
$items = $result->failed()
    ? []
    : ($result->response()?->json('data') ?? []);

// 硬失败：必须成功（IntegrationException 实现 ShouldntReport，避免与集成日志重复、泄密）
$response = $client->fetchItemsOrFail();
```

### 约定

- `send()` / `get()` / `post()` 返回 `IntegrationResult`，失败默认不抛
- `sendOrFail()` / `getOrFail()` / `postOrFail()` 失败抛 `IntegrationException`
- **HTTP 2xx 由基类 `send()` 固定检查**，子类 `validateResponse()` 只做业务码/结构
- `options` 使用 Laravel 原生格式：`query`、`json`、`form_params`、`multipart`
- 失败类型：`connection` / `http` / `business` / `invalid_response`
- 失败日志只由基类记一次；日志故障不覆盖调用结果
- 地址、密钥不进包配置，由具体服务配置管理；构造时校验必填
- 软/硬失败策略由**调用方**决定

### 日志策略

- URI query、请求选项、JSON 响应按 `sensitive_keys` 脱敏后再截断
- `sensitive_keys` 整表替换；追加用 `sensitive_keys_extra`
- 日志尽量带上最终请求快照（含 `configureRequest` 合并的 query）与 `provider_code`
- 不可回退的响应流不读正文；可读流读完后恢复游标
- 一个第三方一个 channel，不要共用一个大文件

## 要求

- PHP `^8.4`
- Laravel `^13.0`

## 安装

```bash
composer require valencio/laravel-toolkit:^0.2
```

## 测试

```bash
composer install
composer test
```
