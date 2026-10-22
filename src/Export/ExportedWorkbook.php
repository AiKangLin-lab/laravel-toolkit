<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Export;

/**
 * 一次成功写入的本地工作簿。
 *
 * 不包含下载 URL 或用户填写的文件名。宿主用 path 返回下载响应。
 */
final readonly class ExportedWorkbook
{
    /**
     * @param  string  $path  本地绝对路径
     * @param  string  $filename  随机存储名，仅作 basename
     * @param  int  $rowCount  数据行数，不含标题和表头
     * @param  int  $byteSize  完成后的文件字节数
     * @param  int  $durationMs  写入耗时毫秒
     */
    public function __construct(
        public string $path,
        public string $filename,
        public int $rowCount,
        public int $byteSize,
        public int $durationMs,
    ) {}
}
