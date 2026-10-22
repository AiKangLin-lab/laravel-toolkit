<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Export;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Valencio\LaravelToolkit\Export\ColumnLetter;

/**
 * 列字母换算，覆盖超过 26 列。
 */
final class ColumnLetterTest extends TestCase
{
    public function test_converts_single_and_double_letters(): void
    {
        $this->assertSame('A', ColumnLetter::fromIndex(1));
        $this->assertSame('Z', ColumnLetter::fromIndex(26));
        $this->assertSame('AA', ColumnLetter::fromIndex(27));
        $this->assertSame('AD', ColumnLetter::fromIndex(30));
        $this->assertSame('AZ', ColumnLetter::fromIndex(52));
        $this->assertSame('BA', ColumnLetter::fromIndex(53));
    }

    public function test_last_column_matches_count(): void
    {
        $this->assertSame('AD', ColumnLetter::lastForCount(30));
    }

    public function test_rejects_non_positive_index(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ColumnLetter::fromIndex(0);
    }
}
