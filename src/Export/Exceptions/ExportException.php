<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Export\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Throwable;

/**
 * 导出写入失败。
 *
 * 客户端文案由宿主决定。已由导出日志记录时不再交给框架默认报告。
 */
class ExportException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  string  $message  技术说明，不是面向用户的文案
     * @param  string|null  $path  已创建的本地文件路径；尚未落盘时为空
     * @param  int  $rowCount  失败前已写入的数据行数
     * @param  Throwable|null  $previous  底层异常
     */
    public function __construct(
        string $message,
        public readonly ?string $path = null,
        public readonly int $rowCount = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
