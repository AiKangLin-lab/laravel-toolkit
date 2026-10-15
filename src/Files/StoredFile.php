<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Files;

/**
 * 一次存储写入的技术结果。
 *
 * 只描述已经写入的对象，不包含 URL、业务表 ID 或调用方身份。
 */
final readonly class StoredFile
{
    /**
     * @param  string  $disk  写入使用的逻辑 disk 名
     * @param  string  $path  对象相对路径
     * @param  int  $size  写入前读取到的字节数
     * @param  string  $mimeType  由文件头识别的 MIME
     * @param  string  $extension  由 MIME 映射的落盘扩展名；无法安全映射时为 bin
     */
    public function __construct(
        public string $disk,
        public string $path,
        public int $size,
        public string $mimeType,
        public string $extension,
    ) {}
}
