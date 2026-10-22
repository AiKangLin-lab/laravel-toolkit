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

## Files

对象存储的技术写入和删除。不生成 URL，不写业务表，不决定权限。

```php
$stored = $storage->store(
    file: $file,
    disk: 'uploads_private',
    directory: 'attachments',
    options: ['visibility' => 'private'],
);

$storage->delete($stored->disk, $stored->path);
```

- `store` 生成独占相对路径后写入；`storeAt` 写入调用方已经分配的路径
- 路径拒绝绝对路径、URL、`..` 和客户端原始扩展名；扩展名来自文件头 MIME
- 底层返回 `false` 或抛出存储异常时抛 `FileStorageException`，并保留 `previous`
- 删除不存在的对象视为成功
- 失败默认记一条 `file_storage` 日志；成功日志由 `laravel-toolkit.files.logging.log_success` 控制
- 日志只记异常类名和通过白名单的 `provider_request_id`，不记文件内容、密钥或签名 URL
- 日志通道优先级：本次参数、包配置 `FILE_STORAGE_LOG_CHANNEL`、默认通道

云厂商驱动由使用方按需安装，不进入本包的强制依赖。

## Export

同步写入 XLSX。包只负责 Excel 写入、基础样式、列宽、行数限额和失败清理。权限、数据范围、筛选、列映射和下载响应留在项目。

需要安装 [php-ext-xlswriter](https://github.com/viest/php-ext-xlswriter/releases)，不进入本包强制依赖。使用恒定内存模式按行顺序写。

```php
use Valencio\LaravelToolkit\Export\ExportOptions;
use Valencio\LaravelToolkit\Export\XlsxExporter;

$workbook = $exporter->write(
    ['编号', '名称'],
    $rows,
    new ExportOptions(
        directory: storage_path('app/private/exports'),
        title: '演示记录',
        columnWidths: ['A:B' => 24],
    ),
);

return response()->download($workbook->path, $downloadName.'.xlsx')->deleteFileAfterSend();
```

- 一次 `write()` 完成，不保留上次表头或数据
- 存储名随机，调用方填写的名称只作下载名
- 字符串按文本写入（前导零、`=` 开头的普通文本）
- 数字和布尔由类型决定；日期时间先格式化成文本
- 超过 `max_rows`（默认 10 万）抛错并删除半成品，不静默截断
- 失败记录异常类名；成功日志由 `laravel-toolkit.export.logging.log_success` 控制，只记行数、耗时和文件大小

## CRUD（阶段 A–D 首版）

按项目 `docs/crud-development-spec.md`：

- **A**：`Query\*`（Filter / Sort / Schema / Input / Applier / ReadView / Limits）
- **B**：读取 `paginate` / `find` / `options`
- **C**：写入 `create` / `update` / `delete`（事务、钩子、字段白名单、行锁）
- **D**：`deleteMany`（deleteAny + 逐条授权/钩子，全成或全回滚）

```php
$record = $crud->create($definition, $request->validated(), new CrudContext(actor: $request->user()));
$crud->update($definition, $id, $request->validated(), $context);
$crud->delete($definition, $id, $context);
$n = $crud->deleteMany($definition, $ids, $context);
```

项目侧需提供：Definition、Policy（含 `create` / `update` / `delete` / `deleteAny`）、FormRequest、Resource。  
包测试需专用 MySQL 测试库，**必须**设置环境变量（库名需含 `test`，禁止回退业务库）：

```bash
TOOLKIT_DB_HOST=127.0.0.1
TOOLKIT_DB_PORT=3306
TOOLKIT_DB_DATABASE=code_base_testing
TOOLKIT_DB_USERNAME=root
TOOLKIT_DB_PASSWORD=...
```

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
