<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Files\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Throwable;

/**
 * 存储读写失败。
 *
 * 保留操作、disk、path 和原始异常供宿主转换。客户端文案由宿主决定，
 * 包日志只记异常类名，因此本异常不再交给框架默认报告，避免原文进入日志。
 */
final class FileStorageException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  string  $message  技术说明，不是面向用户的文案
     * @param  string  $operation  失败操作：store 或 delete
     * @param  string  $disk  逻辑 disk 名
     * @param  string|null  $path  对象相对路径；路径尚未确定时为空
     * @param  Throwable|null  $previous  底层存储异常
     */
    public function __construct(
        string $message,
        public readonly string $operation,
        public readonly string $disk,
        public readonly ?string $path = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
