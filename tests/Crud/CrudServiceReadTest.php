<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Valencio\LaravelToolkit\Crud\CrudContext;
use Valencio\LaravelToolkit\Crud\CrudService;
use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;
use Valencio\LaravelToolkit\Crud\Query\QueryApplier;
use Valencio\LaravelToolkit\Crud\Query\QueryInput;
use Valencio\LaravelToolkit\Crud\Query\QueryLimits;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\CrudWidget;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\CrudWidgetDefinition;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\CrudWidgetPolicy;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\FakeUser;

final class CrudServiceReadTest extends CrudDatabaseTestCase
{
    private CrudService $crud;

    private CrudWidgetDefinition $definition;

    private QueryLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limits = new QueryLimits(defaultPageSize: 10, maxPageSize: 50);
        $gate = new Gate(new Container, static fn () => null);
        $gate->policy(CrudWidget::class, CrudWidgetPolicy::class);

        $this->crud = new CrudService(
            gate: $gate,
            applier: new QueryApplier($this->limits),
            limits: $this->limits,
        );
        $this->definition = new CrudWidgetDefinition;

        $this->seedWidgets([
            ['owner_id' => 1, 'name' => 'alpha', 'status' => 0, 'archived_at' => null, 'created_at' => '2026-01-01'],
            ['owner_id' => 1, 'name' => 'beta', 'status' => 1, 'archived_at' => null, 'created_at' => '2026-01-02'],
            ['owner_id' => 2, 'name' => 'gamma', 'status' => 1, 'archived_at' => null, 'created_at' => '2026-01-03'],
        ]);
    }

    public function test_paginate_respects_owner_scope_and_filters(): void
    {
        $page = $this->crud->paginate(
            $this->definition,
            QueryInput::fromValidated([
                'filter' => ['status' => 1],
                'page' => 1,
                'per_page' => 10,
            ], $this->limits),
            new CrudContext(actor: new FakeUser(1)),
        );

        $this->assertSame(1, $page->total());
        $this->assertSame(['beta'], $page->getCollection()->pluck('name')->all());
    }

    public function test_paginate_requires_view_any(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->crud->paginate(
            $this->definition,
            QueryInput::fromValidated([], $this->limits),
            new CrudContext(actor: null),
        );
    }

    public function test_find_returns_scoped_record_and_404_outside_scope(): void
    {
        $owned = CrudWidget::query()->where('owner_id', 1)->where('name', 'alpha')->firstOrFail();
        $other = CrudWidget::query()->where('owner_id', 2)->firstOrFail();

        $found = $this->crud->find(
            $this->definition,
            $owned->getKey(),
            new CrudContext(actor: new FakeUser(1)),
        );
        $this->assertSame('alpha', $found->name);

        $this->expectException(ModelNotFoundException::class);
        $this->crud->find(
            $this->definition,
            $other->getKey(),
            new CrudContext(actor: new FakeUser(1)),
        );
    }

    public function test_options_uses_explicit_projection_and_limit(): void
    {
        $rows = $this->crud->options(
            $this->definition,
            QueryInput::fromValidated(['limit' => 1], $this->limits),
            new CrudContext(actor: new FakeUser(1)),
        );

        $this->assertCount(1, $rows);
        $this->assertSame(['id', 'name'], array_keys($rows->first()->getAttributes()));
    }

    public function test_options_without_explicit_read_view_is_definition_error(): void
    {
        $bare = new class extends \Valencio\LaravelToolkit\Crud\CrudDefinition
        {
            public function modelClass(): string
            {
                return CrudWidget::class;
            }

            public function scope(
                \Valencio\LaravelToolkit\Crud\CrudOperation $operation,
                \Valencio\LaravelToolkit\Crud\CrudContext $context,
            ): \Illuminate\Database\Eloquent\Builder {
                return CrudWidget::query();
            }

            public function querySchema(): \Valencio\LaravelToolkit\Crud\Query\QuerySchema
            {
                return new \Valencio\LaravelToolkit\Crud\Query\QuerySchema;
            }

            public function createFields(): array
            {
                return [];
            }

            public function updateFields(): array
            {
                return [];
            }
        };

        $this->expectException(InvalidCrudDefinition::class);

        $this->crud->options(
            $bare,
            QueryInput::fromValidated([], $this->limits),
            new CrudContext(actor: new FakeUser(1)),
        );
    }
}
