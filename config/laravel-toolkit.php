<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Integration HTTP
    |--------------------------------------------------------------------------
    |
    | 仅放通用默认策略。具体第三方的地址、密钥、区域等由各服务自己的配置管理。
    |
    */
    'integration' => [
        'http' => [
            'connect_timeout' => 3.0,
            'timeout' => 10.0,
        ],

        'logging' => [
            // 仅作未覆盖时的兜底；业务 Client 应按第三方各自指定 channel（如 tianapi）
            'channel' => env('INTEGRATION_LOG_CHANNEL'),
            // 成功是否记一行；要连 body 一起记时，配合 request_body/response_body=always
            'log_success' => false,
            'request_body' => 'failure_only',
            'response_body' => 'failure_only',
            'max_content_length' => 2000,
            // 整表替换默认脱敏键；仅追加请用 sensitive_keys_extra
            'sensitive_keys' => [
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
            'sensitive_keys_extra' => [],
            'allowed_headers' => [
                'Accept',
                'Content-Type',
                'User-Agent',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CRUD 查询 / 写入限额
    |--------------------------------------------------------------------------
    |
    | 仅放默认 page size、上限与 filter/sort/batch 限额，不放业务模型或字段白名单。
    | 配置类型错误应在构造 QueryLimits 时失败，不能静默变成无上限。
    |
    */
    /*
    |--------------------------------------------------------------------------
    | 文件存储
    |--------------------------------------------------------------------------
    |
    | 只放存储操作日志的通道和成功开关。disk、凭据和场景规则由使用方配置。
    |
    */
    'files' => [
        'logging' => [
            'channel' => env('FILE_STORAGE_LOG_CHANNEL'),
            'log_success' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 同步 XLSX 导出
    |--------------------------------------------------------------------------
    |
    | 只放默认行数上限和日志。输出目录、列映射和下载响应由使用方提供。
    | 需要安装 php-ext-xlswriter。
    |
    */
    'export' => [
        'max_rows' => 100_000,
        'logging' => [
            'channel' => env('EXPORT_LOG_CHANNEL'),
            'log_success' => false,
        ],
    ],

    'crud' => [
        'default_page_size' => 20,
        'max_page_size' => 100,
        'default_options_limit' => 20,
        'max_options_limit' => 100,
        'max_batch_ids' => 100,
        'max_sorts' => 3,
        'max_filters' => 20,
        'max_in_values' => 100,
    ],
];
