<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stringable;
use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;
use Valencio\LaravelToolkit\Crud\Support\CrudValidation;

/**
 * Apply validated filters/sorts to a query.
 *
 * Does not execute, authorize, paginate, change select/eager loads, or read Request.
 */
final class QueryApplier
{
    public function __construct(
        private readonly ?QueryLimits $limits = null,
    ) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, QuerySchema $schema, QueryInput $input): Builder
    {
        $query = clone $query;
        $limits = $this->limits ?? QueryLimits::fromConfig();

        $this->applyFilters($query, $schema, $input, $limits);
        $this->applySorts($query, $schema, $input);

        return $query;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyFilters(
        Builder $query,
        QuerySchema $schema,
        QueryInput $input,
        QueryLimits $limits,
    ): void {
        foreach ($input->filters as $alias => $value) {
            if (! array_key_exists($alias, $schema->filters)) {
                CrudValidation::throw([
                    "filter.{$alias}" => ["Filter [{$alias}] is not allowed."],
                ]);
            }

            // Key present => apply. 0/false are valid; do not use empty().
            $rule = $schema->filters[$alias];
            $this->applyFilterRule($query, $alias, $rule, $value, $limits);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyFilterRule(
        Builder $query,
        string $alias,
        Filter $rule,
        mixed $value,
        QueryLimits $limits,
    ): void {
        match ($rule->type) {
            FilterType::Exact => $this->applyExact($query, $alias, $rule, $value),
            FilterType::Contains => $this->applyLike($query, $alias, $rule, $value, prefix: '%', suffix: '%'),
            FilterType::StartsWith => $this->applyLike($query, $alias, $rule, $value, prefix: '', suffix: '%'),
            FilterType::In => $this->applyIn($query, $alias, $rule, $value, $limits),
            FilterType::Range => $this->applyRange($query, $alias, $rule, $value),
            FilterType::IsNull => $this->applyIsNull($query, $alias, $rule, $value),
            FilterType::Custom => $this->applyCustom($query, $alias, $rule, $value),
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyExact(Builder $query, string $alias, Filter $rule, mixed $value): void
    {
        $column = $this->requireColumn($rule);

        if ($value === null) {
            $query->whereNull($column);

            return;
        }

        if (is_array($value)) {
            CrudValidation::throw([
                "filter.{$alias}" => ["Filter [{$alias}] must not be an array."],
            ]);
        }

        $query->where($column, '=', $value);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyLike(
        Builder $query,
        string $alias,
        Filter $rule,
        mixed $value,
        string $prefix,
        string $suffix,
    ): void {
        $column = $this->requireColumn($rule);

        if (! is_string($value) && ! is_numeric($value)) {
            CrudValidation::throw([
                "filter.{$alias}" => ["Filter [{$alias}] must be a string."],
            ]);
        }

        $escaped = $this->escapeLike((string) $value);
        $pattern = $prefix.$escaped.$suffix;
        // 显式 ESCAPE，避免 SQLite 等驱动不认默认反斜杠转义
        $wrapped = $query->getGrammar()->wrap($column);
        $query->whereRaw("{$wrapped} LIKE ? ESCAPE ?", [$pattern, '\\']);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyIn(
        Builder $query,
        string $alias,
        Filter $rule,
        mixed $value,
        QueryLimits $limits,
    ): void {
        $column = $this->requireColumn($rule);

        if (! is_array($value)) {
            CrudValidation::throw([
                "filter.{$alias}" => ["Filter [{$alias}] must be an array."],
            ]);
        }

        if (count($value) > $limits->maxInValues) {
            CrudValidation::throw([
                "filter.{$alias}" => ["Filter [{$alias}] may contain at most {$limits->maxInValues} values."],
            ]);
        }

        foreach ($value as $item) {
            if (is_array($item) || (is_object($item) && ! $item instanceof Stringable)) {
                CrudValidation::throw([
                    "filter.{$alias}" => ["Filter [{$alias}] values must be scalars."],
                ]);
            }
        }

        // Empty array => match zero rows (never "no filter").
        $query->whereIn($column, array_values($value));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyRange(Builder $query, string $alias, Filter $rule, mixed $value): void
    {
        $column = $this->requireColumn($rule);

        if (! is_array($value)) {
            CrudValidation::throw([
                "filter.{$alias}" => ["Filter [{$alias}] must be an object with from and/or to."],
            ]);
        }

        $fromProvided = array_key_exists('from', $value);
        $toProvided = array_key_exists('to', $value);

        if (! $fromProvided && ! $toProvided) {
            CrudValidation::throw([
                "filter.{$alias}" => ["Filter [{$alias}] requires from and/or to."],
            ]);
        }

        $from = $fromProvided ? $value['from'] : null;
        $to = $toProvided ? $value['to'] : null;

        if ($fromProvided && $toProvided) {
            if (get_debug_type($from) !== get_debug_type($to)) {
                CrudValidation::throw([
                    "filter.{$alias}" => ["Filter [{$alias}] from/to must be the same type."],
                ]);
            }

            if (is_string($from) || is_int($from) || is_float($from)) {
                if ($from > $to) {
                    CrudValidation::throw([
                        "filter.{$alias}" => ["Filter [{$alias}] from must not be greater than to."],
                    ]);
                }
            }
        }

        if ($fromProvided) {
            $query->where($column, '>=', $from);
        }
        if ($toProvided) {
            $query->where($column, '<=', $to);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyIsNull(Builder $query, string $alias, Filter $rule, mixed $value): void
    {
        $column = $this->requireColumn($rule);

        if (! is_bool($value)) {
            CrudValidation::throw([
                "filter.{$alias}" => ["Filter [{$alias}] must be a boolean."],
            ]);
        }

        if ($value) {
            $query->whereNull($column);
        } else {
            $query->whereNotNull($column);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyCustom(Builder $query, string $alias, Filter $rule, mixed $value): void
    {
        $callback = $rule->callback;
        if ($callback === null) {
            throw new InvalidCrudDefinition("Custom filter [{$alias}] is missing a callback.");
        }

        $query->where(static function (Builder $nested) use ($callback, $value): void {
            $callback($nested, $value);
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applySorts(Builder $query, QuerySchema $schema, QueryInput $input): void
    {
        $requested = $input->sorts;
        if ($requested === []) {
            foreach ($schema->defaultSort as $token) {
                $direction = str_starts_with($token, '-') ? 'desc' : 'asc';
                $alias = ltrim($token, '-');
                $requested[] = ['alias' => $alias, 'direction' => $direction];
            }
        }

        $orderedColumns = [];
        foreach ($requested as $item) {
            $alias = $item['alias'];
            if (! array_key_exists($alias, $schema->sorts)) {
                CrudValidation::throw([
                    'sort' => ["Sort [{$alias}] is not allowed."],
                ]);
            }

            $column = $schema->sorts[$alias]->column;
            $query->orderBy($column, $item['direction']);
            $orderedColumns[] = $column;
        }

        $keyName = $query->getModel()->getKeyName();
        if (! in_array($keyName, $orderedColumns, true)) {
            $query->orderBy($keyName, 'asc');
        }
    }

    private function requireColumn(Filter $rule): string
    {
        if ($rule->column === null || $rule->column === '') {
            throw new InvalidCrudDefinition('Filter column is required for this rule type.');
        }

        return $rule->column;
    }

    /**
     * Treat user % and _ as literals; also escape backslashes.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\%', '\_'],
            $value,
        );
    }
}
