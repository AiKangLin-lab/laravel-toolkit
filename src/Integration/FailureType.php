<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Integration;

/**
 * 第三方集成失败类型。
 */
enum FailureType: string
{
    /**
     * 连接失败、超时等传输层故障。
     */
    case Connection = 'connection';

    /**
     * HTTP 非成功状态。
     */
    case Http = 'http';

    /**
     * 第三方业务状态失败。
     */
    case Business = 'business';

    /**
     * 响应结构或格式不符合约定。
     */
    case InvalidResponse = 'invalid_response';
}
