<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud;

use Illuminate\Validation\ValidationException;
use Valencio\LaravelToolkit\Crud\Query\Filter;
use Valencio\LaravelToolkit\Crud\Query\QueryApplier;
use Valencio\LaravelToolkit\Crud\Query\QueryInput;
use Valencio\LaravelToolkit\Crud\Query\QueryLimits;
use Valencio\LaravelToolkit\Crud\Query\QuerySchema;
use Valencio\LaravelToolkit\Crud\Query\Sort;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\CrudWidget;

final class QueryApplierTest extends CrudDatabaseTestCase
{
    private QueryApplier $applier;

    private QueryLimits $limits;

    private QuerySchema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limits = new QueryLimits(maxInValues: 5, maxFilters: 10, maxSorts: 3);
        $this->applier = new QueryApplier($this->limits);
        $this->schema = new QuerySchema(
            filters: [
                'name' => Filter::contains('name'),
                'prefix' => Filter::startsWith('name'),
                'status' => Filter::exact('status'),
                'ids' => Filter::in('id'),
                'created_at' => Filter::range('created_at'),
                'archived' => Filter::isNull('archived_at'),
                'keyword' => Filter::custom(static function ($query, mixed $value): void {
                    $query->where(static function ($nested) use ($value): void {
                        $nested->where('name', 'like', '%'.$value.'%')
                            ->orWhere('name', '=', $value);
                    });
                }),
            ],
            sorts: [
                'name' => Sort::column('name'),
                'created_at' => Sort::column('created_at'),
                'id' => Sort::column('id'),
            ],
            defaultSort: ['-created_at'],
        );

        $this->seedWidgets([
            ['owner_id' => 1, 'name' => 'alpha', 'status' => 0, 'archived_at' => null, 'created_at' => '2026-01-03'],
            ['owner_id' => 1, 'name' => 'beta%wild', 'status' => 1, 'archived_at' => null, 'created_at' => '2026-01-02'],
            ['owner_id' => 2, 'name' => 'gamma', 'status' => 1, 'archived_at' => '2026-01-01', 'created_at' => '2026-01-01'],
            ['owner_id' => 1, 'name' => 'delta', 'status' => 0, 'archived_at' => null, 'created_at' => '2026-01-02'],
        ]);
    }

    public function test_exact_zero_and_false_are_applied(): void
    {
        $input = QueryInput::fromValidated([
            'filter' => ['status' => 0],
        ], $this->limits);

        $ids = $this->applier
            ->apply(CrudWidget::query(), $this->schema, $input)
            ->pluck('id')
            ->all();

        $this->assertSame([1, 4], $ids);
    }

    public function test_missing_filter_key_is_not_applied(): void
    {
        $input = QueryInput::fromValidated([], $this->limits);

        $count = $this->applier
            ->apply(CrudWidget::query(), $this->schema, $input)
            ->count();

        $this->assertSame(4, $count);
    }

    public function test_contains_treats_percent_and_underscore_literally(): void
    {
        $input = QueryInput::fromValidated([
            'filter' => ['name' => '%wild'],
        ], $this->limits);

        $names = $this->applier
            ->apply(CrudWidget::query(), $this->schema, $input)
            ->pluck('name')
            ->all();

        $this->assertSame(['beta%wild'], $names);
    }

    public function test_empty_in_matches_zero_rows(): void
    {
        $input = QueryInput::fromValidated([
            'filter' => ['ids' => []],
        ], $this->limits);

        $count = $this->applier
            ->apply(CrudWidget::query(), $this->schema, $input)
            ->count();

        $this->assertSame(0, $count);
    }

    public function test_unknown_filter_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $input = new QueryInput(
            filters: ['secret' => 1],
            sorts: [],
            page: null,
            perPage: null,
            limit: null,
            pageProvided: false,
            perPageProvided: false,
            limitProvided: false,
        );

        $this->applier->apply(CrudWidget::query(), $this->schema, $input);
    }

    public function test_default_sort_and_primary_key_tiebreaker(): void
    {
        $input = QueryInput::fromValidated([], $this->limits);

        $ids = $this->applier
            ->apply(CrudWidget::query(), $this->schema, $input)
            ->pluck('id')
            ->all();

        // created_at desc: 2026-01-03 (1), then 2026-01-02 (2 and 4) with id asc, then 2026-01-01 (3)
        $this->assertSame([1, 2, 4, 3], $ids);
    }

    public function test_client_sort_replaces_default_sort(): void
    {
        $input = QueryInput::fromValidated([
            'sort' => 'name',
        ], $this->limits);

        $names = $this->applier
            ->apply(CrudWidget::query(), $this->schema, $input)
            ->pluck('name')
            ->all();

        $this->assertSame(['alpha', 'beta%wild', 'delta', 'gamma'], $names);
    }

    public function test_owner_scope_and_keyword_or_cannot_escape_scope(): void
    {
        $scoped = CrudWidget::query()->where('owner_id', 1);
        $input = QueryInput::fromValidated([
            'filter' => ['keyword' => 'gamma'],
        ], $this->limits);

        $count = $this->applier->apply($scoped, $this->schema, $input)->count();

        $this->assertSame(0, $count);
    }

    public function test_is_null_and_range_filters(): void
    {
        // isNull(true)=IS NULL；要「已归档」用 false → IS NOT NULL
        $archived = QueryInput::fromValidated([
            'filter' => ['archived' => false],
        ], $this->limits);

        $this->assertSame(
            ['gamma'],
            $this->applier->apply(CrudWidget::query(), $this->schema, $archived)->pluck('name')->all(),
        );

        $range = QueryInput::fromValidated([
            'filter' => [
                'created_at' => ['from' => '2026-01-02', 'to' => '2026-01-02'],
            ],
        ], $this->limits);

        $this->assertSame(
            [2, 4],
            $this->applier->apply(CrudWidget::query(), $this->schema, $range)->pluck('id')->all(),
        );
    }

    public function test_apply_clones_query_and_does_not_mutate_original(): void
    {
        $original = CrudWidget::query()->where('owner_id', 1);
        $input = QueryInput::fromValidated([
            'filter' => ['status' => 1],
        ], $this->limits);

        $filtered = $this->applier->apply($original, $this->schema, $input);

        $this->assertSame(1, $filtered->count());
        $this->assertSame(3, $original->count());
    }

    public function test_starts_with_filter(): void
    {
        $input = QueryInput::fromValidated([
            'filter' => ['prefix' => 'be'],
        ], $this->limits);

        $this->assertSame(
            ['beta%wild'],
            $this->applier->apply(CrudWidget::query(), $this->schema, $input)->pluck('name')->all(),
        );
    }
}
