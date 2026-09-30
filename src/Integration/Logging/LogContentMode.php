<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Integration\Logging;

/**
 * 请求/响应内容的记录时机。
 */
enum LogContentMode: string
{
    case Never = 'never';
    case FailureOnly = 'failure_only';
    case Always = 'always';
}
