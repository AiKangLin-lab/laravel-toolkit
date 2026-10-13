<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Valencio\LaravelToolkit\Crud\Query\QueryInput;
use Valencio\LaravelToolkit\Crud\Query\QueryLimits;

final class QueryInputTest extends TestCase
{
    private QueryLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limits = new QueryLimits(
            defaultPageSize: 20,
            maxPageSize: 50,
            defaultOptionsLimit: 10,
            maxOptionsLimit: 30,
            maxSorts: 2,
            maxFilters: 3,
            maxInValues: 5,
        );
    }

    public function test_from_validated_parses_sort_and_keeps_zero_filter_values(): void
    {
        $input = QueryInput::fromValidated([
            'filter' => ['status' => 0, 'active' => false],
            'sort' => '-created_at,id',
        ], $this->limits);

        $this->assertSame(0, $input->filters['status']);
        $this->assertFalse($input->filters['active']);
        $this->assertSame(
            [
                ['alias' => 'created_at', 'direction' => 'desc'],
                ['alias' => 'id', 'direction' => 'asc'],
            ],
            $input->sorts,
        );
        $this->assertFalse($input->pageProvided);
        $this->assertNull($input->page);
    }

    public function test_rejects_too_many_filters_and_sorts(): void
    {
        try {
            QueryInput::fromValidated([
                'filter' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4],
            ], $this->limits);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('filter', $e->errors());
        }

        try {
            QueryInput::fromValidated([
                'sort' => 'a,b,c',
            ], $this->limits);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sort', $e->errors());
        }
    }

    public function test_rejects_string_page_without_coercion(): void
    {
        $this->expectException(ValidationException::class);

        QueryInput::fromValidated(['page' => '2'], $this->limits);
    }

    public function test_for_pagination_defaults_and_rejects_limit(): void
    {
        $input = QueryInput::fromValidated([], $this->limits)->forPagination($this->limits);

        $this->assertSame(1, $input->page);
        $this->assertSame(20, $input->perPage);

        $this->expectException(ValidationException::class);
        QueryInput::fromValidated(['limit' => 5], $this->limits)->forPagination($this->limits);
    }

    public function test_for_options_defaults_and_rejects_page(): void
    {
        $input = QueryInput::fromValidated([], $this->limits)->forOptions($this->limits);

        $this->assertSame(10, $input->limit);

        $this->expectException(ValidationException::class);
        QueryInput::fromValidated(['page' => 1], $this->limits)->forOptions($this->limits);
    }

    public function test_rejects_per_page_above_max(): void
    {
        $this->expectException(ValidationException::class);

        QueryInput::fromValidated(['per_page' => 51], $this->limits);
    }
}
