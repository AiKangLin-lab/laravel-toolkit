<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud;

use PHPUnit\Framework\TestCase;
use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;
use Valencio\LaravelToolkit\Crud\Query\Filter;
use Valencio\LaravelToolkit\Crud\Query\QuerySchema;
use Valencio\LaravelToolkit\Crud\Query\Sort;

final class QuerySchemaTest extends TestCase
{
    public function test_rejects_undeclared_default_sort_alias(): void
    {
        $this->expectException(InvalidCrudDefinition::class);

        new QuerySchema(
            sorts: ['id' => Sort::column('id')],
            defaultSort: ['-created_at'],
        );
    }

    public function test_rejects_empty_filter_column(): void
    {
        $this->expectException(InvalidCrudDefinition::class);

        Filter::exact('  ');
    }

    public function test_accepts_valid_schema(): void
    {
        $schema = new QuerySchema(
            filters: [
                'name' => Filter::contains('name'),
                'status' => Filter::exact('status'),
            ],
            sorts: [
                'name' => Sort::column('name'),
                'id' => Sort::column('id'),
            ],
            defaultSort: ['-name'],
        );

        $this->assertArrayHasKey('name', $schema->filters);
        $this->assertSame(['-name'], $schema->defaultSort);
    }
}
