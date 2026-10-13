<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Query;

use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;

/**
 * 客户端排序别名到数据库列的映射（不可变）。
 */
final readonly class Sort
{
    private function __construct(
        public string $column,
    ) {}

    public static function column(string $column): self
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidCrudDefinition('Sort column must not be empty.');
        }

        return new self($column);
    }
}
