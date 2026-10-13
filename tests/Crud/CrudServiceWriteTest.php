<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Valencio\LaravelToolkit\Crud\CrudContext;
use Valencio\LaravelToolkit\Crud\CrudService;
use Valencio\LaravelToolkit\Crud\Query\QueryApplier;
use Valencio\LaravelToolkit\Crud\Query\QueryLimits;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\CrudWidget;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\CrudWidgetDefinition;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\CrudWidgetPolicy;
use Valencio\LaravelToolkit\Tests\Crud\Fixtures\FakeUser;

final class CrudServiceWriteTest extends CrudDatabaseTestCase
{
    private CrudService $crud;

    private CrudWidgetDefinition $definition;

    private QueryLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limits = new QueryLimits(defaultPageSize: 10, maxPageSize: 50, maxBatchIds: 10);
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
            ['owner_id' => 2, 'name' => 'gamma', 'status' => 1, 'archived_at' => null, 'created_at' => '2026-01-03'],
        ]);
    }

    public function test_create_injects_owner_and_rejects_extra_fields(): void
    {
        $record = $this->crud->create(
            $this->definition,
            ['name' => 'new-one', 'status' => 1],
            new CrudContext(actor: new FakeUser(1)),
        );

        $this->assertSame(1, (int) $record->owner_id);
        $this->assertSame('new-one', $record->name);

        try {
            $this->crud->create(
                $this->definition,
                ['name' => 'x', 'status' => 0, 'owner_id' => 99],
                new CrudContext(actor: new FakeUser(1)),
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('owner_id', $e->errors());
        }
    }

    public function test_update_is_partial_and_scope_enforced(): void
    {
        $owned = CrudWidget::query()->where('owner_id', 1)->firstOrFail();
        $other = CrudWidget::query()->where('owner_id', 2)->firstOrFail();

        $updated = $this->crud->update(
            $this->definition,
            $owned->getKey(),
            ['name' => 'alpha-2'],
            new CrudContext(actor: new FakeUser(1)),
        );
        $this->assertSame('alpha-2', $updated->name);
        $this->assertSame(0, (int) $updated->status);

        $this->expectException(ModelNotFoundException::class);
        $this->crud->update(
            $this->definition,
            $other->getKey(),
            ['name' => 'hack'],
            new CrudContext(actor: new FakeUser(1)),
        );
    }

    public function test_update_rejects_empty_payload(): void
    {
        $owned = CrudWidget::query()->where('owner_id', 1)->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->crud->update(
            $this->definition,
            $owned->getKey(),
            [],
            new CrudContext(actor: new FakeUser(1)),
        );
    }

    public function test_delete_and_delete_many(): void
    {
        $a = $this->crud->create(
            $this->definition,
            ['name' => 'del-a', 'status' => 0],
            new CrudContext(actor: new FakeUser(1)),
        );
        $b = $this->crud->create(
            $this->definition,
            ['name' => 'del-b', 'status' => 0],
            new CrudContext(actor: new FakeUser(1)),
        );

        $this->crud->delete(
            $this->definition,
            $a->getKey(),
            new CrudContext(actor: new FakeUser(1)),
        );
        $this->assertNull(CrudWidget::query()->find($a->getKey()));

        $count = $this->crud->deleteMany(
            $this->definition,
            [$b->getKey()],
            new CrudContext(actor: new FakeUser(1)),
        );
        $this->assertSame(1, $count);
        $this->assertContains((int) $b->getKey(), $this->definition->deletedIds);
    }

    public function test_delete_many_rejects_duplicates_and_partial_scope(): void
    {
        $owned = CrudWidget::query()->where('owner_id', 1)->firstOrFail();
        $other = CrudWidget::query()->where('owner_id', 2)->firstOrFail();

        try {
            $this->crud->deleteMany(
                $this->definition,
                [$owned->getKey(), $owned->getKey()],
                new CrudContext(actor: new FakeUser(1)),
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ids', $e->errors());
        }

        $this->expectException(ModelNotFoundException::class);
        $this->crud->deleteMany(
            $this->definition,
            [$owned->getKey(), $other->getKey()],
            new CrudContext(actor: new FakeUser(1)),
        );
    }

    public function test_create_requires_authorization(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->crud->create(
            $this->definition,
            ['name' => 'x', 'status' => 0],
            new CrudContext(actor: null),
        );
    }
}
