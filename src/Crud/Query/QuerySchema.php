<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Query;

use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;

/**
 * 允许的 Filter / Sort 与默认排序声明（不可变）。
 */
final readonly class QuerySchema
{
    /**
     * @param  array<string, Filter>  $filters
     * @param  array<string, Sort>  $sorts
     * @param  list<string>  $defaultSort  别名列表，前缀 `-` 表示 desc
     */
    public function __construct(
        public array $filters = [],
        public array $sorts = [],
        public array $defaultSort = [],
    ) {
        $this->assertAliasMap($this->filters, 'filter');
        $this->assertAliasMap($this->sorts, 'sort');

        foreach ($this->defaultSort as $index => $alias) {
            if (! is_string($alias) || $alias === '' || $alias === '-') {
                throw new InvalidCrudDefinition(
                    "QuerySchema defaultSort[{$index}] must be a non-empty sort alias."
                );
            }

            $name = ltrim($alias, '-');
            if ($name === '' || ! isset($this->sorts[$name])) {
                throw new InvalidCrudDefinition(
                    "QuerySchema defaultSort alias [{$alias}] is not declared in sorts."
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function assertAliasMap(array $map, string $kind): void
    {
        foreach ($map as $alias => $rule) {
            if (! is_string($alias) || $alias === '') {
                throw new InvalidCrudDefinition("QuerySchema {$kind} alias must be a non-empty string.");
            }

            $expected = $kind === 'filter' ? Filter::class : Sort::class;
            if (! $rule instanceof $expected) {
                throw new InvalidCrudDefinition(
                    "QuerySchema {$kind} [{$alias}] must be an instance of {$expected}."
                );
            }
        }
    }
}
