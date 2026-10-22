<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Export\Exceptions;

use RuntimeException;

/**
 * 未安装 php-ext-xlswriter。
 *
 * 这是部署配置错误，交给框架默认报告。
 */
final class XlswriterMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'XLSX export requires the xlswriter PHP extension. See https://github.com/viest/php-ext-xlswriter/releases',
        );
    }
}
