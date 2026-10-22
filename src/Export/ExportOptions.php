<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Export;

use InvalidArgumentException;

/**
 * 一次 XLSX 写入的技术选项。
 *
 * 目录由宿主提供，必须是本地私有路径。用户填写的下载名不要放进这里。
 */
final readonly class ExportOptions
{
    /**
     * @param  string  $directory  本地输出目录的绝对路径
     * @param  int|null  $maxRows  数据行上限；为空时使用导出器默认值
     * @param  string  $title  首行合并标题；空字符串表示不写标题行
     * @param  array<string, int|float>  $columnWidths  xlswriter setColumn 范围到宽度
     * @param  string  $sheetName  工作表名
     */
    public function __construct(
        public string $directory,
        public ?int $maxRows = null,
        public string $title = '',
        public array $columnWidths = [],
        public string $sheetName = 'Sheet1',
    ) {
        if ($this->directory === '') {
            throw new InvalidArgumentException('Export directory must not be empty.');
        }

        if ($this->maxRows !== null && $this->maxRows < 1) {
            throw new InvalidArgumentException('Export max rows must be at least 1.');
        }

        if ($this->sheetName === '' || strlen($this->sheetName) > 31) {
            throw new InvalidArgumentException('Export sheet name must be 1 to 31 characters.');
        }

        if (preg_match('/[:\\\\\\/?*\\[\\]]/', $this->sheetName) === 1) {
            throw new InvalidArgumentException('Export sheet name contains illegal characters.');
        }

        foreach ($this->columnWidths as $range => $width) {
            if (! is_string($range) || $range === '') {
                throw new InvalidArgumentException('Export column width range must be a non-empty string.');
            }

            if (! is_int($width) && ! is_float($width)) {
                throw new InvalidArgumentException('Export column width must be a number.');
            }

            if ($width <= 0) {
                throw new InvalidArgumentException('Export column width must be greater than 0.');
            }
        }
    }
}
