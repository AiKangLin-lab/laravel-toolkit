<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Query;

use Closure;
use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;

/**
 * 不可变筛选规则；静态工厂只构造，不执行查询。
 */
final readonly class Filter
{
    /**
     * @param  Closure(mixed, mixed): void|null  $callback  仅 Custom；签名在 QueryApplier 中再收窄
     */
    private function __construct(
        public FilterType $type,
        public ?string $column,
        public ?Closure $callback = null,
    ) {}

    public static function exact(string $column): self
    {
        return self::forColumn(FilterType::Exact, $column);
    }

    public static function contains(string $column): self
    {
        return self::forColumn(FilterType::Contains, $column);
    }

    public static function startsWith(string $column): self
    {
        return self::forColumn(FilterType::StartsWith, $column);
    }

    public static function in(string $column): self
    {
        return self::forColumn(FilterType::In, $column);
    }

    public static function range(string $column): self
    {
        return self::forColumn(FilterType::Range, $column);
    }

    public static function isNull(string $column): self
    {
        return self::forColumn(FilterType::IsNull, $column);
    }

    /**
     * 自定义筛选。闭包仅允许向嵌套 where 组追加条件。
     *
     * @param  Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>, mixed): void  $callback
     */
    public static function custom(Closure $callback): self
    {
        return new self(FilterType::Custom, null, $callback);
    }

    private static function forColumn(FilterType $type, string $column): self
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidCrudDefinition('Filter column must not be empty.');
        }

        return new self($type, $column);
    }
}
