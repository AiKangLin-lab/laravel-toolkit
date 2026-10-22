<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Export;

use InvalidArgumentException;

/**
 * Excel 列字母。支持超过 26 列的 AA、AB。
 */
final class ColumnLetter
{
    /**
     * 将 1 起始的列序号转为列字母。
     *
     * @param  int  $index  列序号，从 1 起
     */
    public static function fromIndex(int $index): string
    {
        if ($index < 1) {
            throw new InvalidArgumentException('Excel column index must be at least 1.');
        }

        $letters = '';
        $current = $index;
        while ($current > 0) {
            $current--;
            $letters = chr(65 + ($current % 26)).$letters;
            $current = intdiv($current, 26);
        }

        return $letters;
    }

    /**
     * 返回给定列数对应的最后一列字母。
     *
     * @param  int  $count  列数，至少 1
     */
    public static function lastForCount(int $count): string
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Excel column count must be at least 1.');
        }

        return self::fromIndex($count);
    }
}
