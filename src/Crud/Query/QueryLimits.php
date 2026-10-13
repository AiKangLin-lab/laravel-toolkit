<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Query;

use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;

/**
 * CRUD 查询限额（不可变）。优先读配置，缺省用规范默认值。
 */
final readonly class QueryLimits
{
    public function __construct(
        public int $defaultPageSize = 20,
        public int $maxPageSize = 100,
        public int $defaultOptionsLimit = 20,
        public int $maxOptionsLimit = 100,
        public int $maxBatchIds = 100,
        public int $maxSorts = 3,
        public int $maxFilters = 20,
        public int $maxInValues = 100,
    ) {
        foreach (
            [
                'defaultPageSize' => $this->defaultPageSize,
                'maxPageSize' => $this->maxPageSize,
                'defaultOptionsLimit' => $this->defaultOptionsLimit,
                'maxOptionsLimit' => $this->maxOptionsLimit,
                'maxBatchIds' => $this->maxBatchIds,
                'maxSorts' => $this->maxSorts,
                'maxFilters' => $this->maxFilters,
                'maxInValues' => $this->maxInValues,
            ] as $name => $value
        ) {
            if ($value < 1) {
                throw new InvalidCrudDefinition("QueryLimits.{$name} must be a positive integer.");
            }
        }

        if ($this->defaultPageSize > $this->maxPageSize) {
            throw new InvalidCrudDefinition('QueryLimits.defaultPageSize cannot exceed maxPageSize.');
        }

        if ($this->defaultOptionsLimit > $this->maxOptionsLimit) {
            throw new InvalidCrudDefinition('QueryLimits.defaultOptionsLimit cannot exceed maxOptionsLimit.');
        }
    }

    /**
     * 从 laravel-toolkit.crud 配置构造；无容器时用规范默认值。
     */
    public static function fromConfig(): self
    {
        $crud = [];
        if (function_exists('config')) {
            $value = config('laravel-toolkit.crud');
            if (is_array($value)) {
                $crud = $value;
            }
        }

        return new self(
            defaultPageSize: self::positiveInt($crud, 'default_page_size', 20),
            maxPageSize: self::positiveInt($crud, 'max_page_size', 100),
            defaultOptionsLimit: self::positiveInt($crud, 'default_options_limit', 20),
            maxOptionsLimit: self::positiveInt($crud, 'max_options_limit', 100),
            maxBatchIds: self::positiveInt($crud, 'max_batch_ids', 100),
            maxSorts: self::positiveInt($crud, 'max_sorts', 3),
            maxFilters: self::positiveInt($crud, 'max_filters', 20),
            maxInValues: self::positiveInt($crud, 'max_in_values', 100),
        );
    }

    /**
     * @param  array<string, mixed>  $crud
     */
    private static function positiveInt(array $crud, string $key, int $default): int
    {
        if (! array_key_exists($key, $crud)) {
            return $default;
        }

        $value = $crud[$key];
        if (! is_int($value) || $value < 1) {
            throw new InvalidCrudDefinition(
                "laravel-toolkit.crud.{$key} must be a positive integer."
            );
        }

        return $value;
    }
}
