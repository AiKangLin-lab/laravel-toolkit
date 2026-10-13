<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud\Fixtures;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Valencio\LaravelToolkit\Crud\CrudContext;
use Valencio\LaravelToolkit\Crud\CrudDefinition;
use Valencio\LaravelToolkit\Crud\CrudOperation;
use Valencio\LaravelToolkit\Crud\Query\Filter;
use Valencio\LaravelToolkit\Crud\Query\QuerySchema;
use Valencio\LaravelToolkit\Crud\Query\ReadView;
use Valencio\LaravelToolkit\Crud\Query\Sort;

/**
 * @extends CrudDefinition<CrudWidget>
 */
final class CrudWidgetDefinition extends CrudDefinition
{
    /** @var list<int> */
    public array $deletedIds = [];

    public function modelClass(): string
    {
        return CrudWidget::class;
    }

    public function scope(CrudOperation $operation, CrudContext $context): Builder
    {
        if ($context->actor === null) {
            throw new AuthenticationException;
        }

        return CrudWidget::query()->where(
            'owner_id',
            $context->actor->getAuthIdentifier(),
        );
    }

    public function querySchema(): QuerySchema
    {
        return new QuerySchema(
            filters: [
                'name' => Filter::contains('name'),
                'status' => Filter::exact('status'),
            ],
            sorts: [
                'id' => Sort::column('id'),
                'name' => Sort::column('name'),
                'created_at' => Sort::column('created_at'),
            ],
            defaultSort: ['id'],
        );
    }

    public function createFields(): array
    {
        return ['name', 'status'];
    }

    public function updateFields(): array
    {
        return ['name', 'status'];
    }

    public function serverFields(CrudOperation $operation): array
    {
        return $operation === CrudOperation::Create ? ['owner_id'] : [];
    }

    public function beforeCreate(array $data, CrudContext $context): array
    {
        if ($context->actor === null) {
            throw new AuthenticationException;
        }

        $data['owner_id'] = $context->actor->getAuthIdentifier();

        return $data;
    }

    public function afterDelete(\Illuminate\Database\Eloquent\Model $record, CrudContext $context): void
    {
        $this->deletedIds[] = (int) $record->getKey();
    }

    public function readView(CrudOperation $operation): ReadView
    {
        return match ($operation) {
            CrudOperation::Options => new ReadView(columns: ['id', 'name']),
            CrudOperation::List => new ReadView(columns: ['id', 'name', 'status', 'owner_id', 'created_at']),
            default => new ReadView,
        };
    }
}
