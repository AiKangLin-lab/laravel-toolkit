<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Export\Exceptions;

/**
 * 导出行数超过写入限额。
 *
 * 超限后不会保留半成品文件，也不会静默截断。
 */
final class ExportLimitExceededException extends ExportException
{
    /**
     * @param  int  $maxRows  本次允许的最大数据行数
     * @param  int  $rowCount  触发限额时已计入的行数（含越界这一行）
     */
    public function __construct(
        public readonly int $maxRows,
        int $rowCount,
    ) {
        parent::__construct(
            sprintf('Export exceeded max rows of %d.', $maxRows),
            null,
            $rowCount,
        );
    }
}
