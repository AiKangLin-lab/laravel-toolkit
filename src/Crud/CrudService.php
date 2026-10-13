<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Valencio\LaravelToolkit\Crud\Exceptions\CrudPersistenceException;
use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;
use Valencio\LaravelToolkit\Crud\Query\QueryApplier;
use Valencio\LaravelToolkit\Crud\Query\QueryInput;
use Valencio\LaravelToolkit\Crud\Query\QueryLimits;
use Valencio\LaravelToolkit\Crud\Query\ReadView;
use Valencio\LaravelToolkit\Crud\Support\CrudValidation;

/**
 * CRUD 固定读写流程入口。
 *
 * 方法均为实例方法，不提供静态 Facade。
 */
final class CrudService
{
    public function __construct(
        private readonly Gate $gate,
        private readonly QueryApplier $applier = new QueryApplier,
        private readonly ?QueryLimits $limits = null,
    ) {}

    /**
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     * @return LengthAwarePaginator<int, TModel>
     *
     * @throws AuthorizationException
     */
    public function paginate(
        CrudDefinition $definition,
        QueryInput $input,
        CrudContext $context,
    ): LengthAwarePaginator {
        $modelClass = $this->assertModelClass($definition);
        $this->authorize($context, 'viewAny', $modelClass);

        $limits = $this->limits ?? QueryLimits::fromConfig();
        $input = $input->forPagination($limits);

        $query = $this->scopedQuery($definition, CrudOperation::List, $context);
        $query = $this->applier->apply($query, $definition->querySchema(), $input);
        $this->applyReadView($query, $definition->readView(CrudOperation::List));

        /** @var LengthAwarePaginator<int, TModel> $page */
        $page = $query->paginate(
            perPage: (int) $input->perPage,
            columns: ['*'],
            pageName: 'page',
            page: (int) $input->page,
        );

        return $page;
    }

    /**
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     * @return TModel
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function find(
        CrudDefinition $definition,
        int|string $id,
        CrudContext $context,
    ): Model {
        $modelClass = $this->assertModelClass($definition);

        $query = $this->scopedQuery($definition, CrudOperation::View, $context);
        $this->applyReadView($query, $definition->readView(CrudOperation::View));

        /** @var TModel|null $record */
        $record = (clone $query)->whereKey($id)->first();
        if ($record === null) {
            throw (new ModelNotFoundException)->setModel($modelClass, [$id]);
        }

        $this->authorize($context, 'view', $record);

        return $record;
    }

    /**
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     * @return Collection<int, TModel>
     *
     * @throws AuthorizationException
     */
    public function options(
        CrudDefinition $definition,
        QueryInput $input,
        CrudContext $context,
    ): Collection {
        $modelClass = $this->assertModelClass($definition);
        $this->authorize($context, 'viewAny', $modelClass);

        $limits = $this->limits ?? QueryLimits::fromConfig();
        $input = $input->forOptions($limits);

        $query = $this->scopedQuery($definition, CrudOperation::Options, $context);
        $query = $this->applier->apply($query, $definition->querySchema(), $input);
        $this->applyReadView($query, $definition->readView(CrudOperation::Options));

        /** @var Collection<int, TModel> $rows */
        $rows = $query->limit((int) $input->limit)->get();

        return $rows;
    }

    /**
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     * @param  array<string, mixed>  $data  已验证的客户端属性
     * @return TModel
     *
     * @throws AuthorizationException
     */
    public function create(
        CrudDefinition $definition,
        array $data,
        CrudContext $context,
    ): Model {
        $modelClass = $this->assertModelClass($definition);
        $this->assertClientKeys($data, $definition->createFields(), 'create');
        $this->authorize($context, 'create', $modelClass);

        return $this->onModelConnection($modelClass, function () use ($definition, $data, $context, $modelClass): Model {
            $final = $definition->beforeCreate($data, $context);
            $this->assertHookResultIsArray($final, 'beforeCreate');
            $this->assertFinalKeys(
                $final,
                $definition->createFields(),
                $definition->serverFields(CrudOperation::Create),
                'beforeCreate',
            );

            /** @var TModel $record */
            $record = new $modelClass;
            $this->assertFillable($record, $final);
            $record->fill($final);

            if ($record->save() === false) {
                throw new CrudPersistenceException(
                    $modelClass.'::save() returned false during create.'
                );
            }

            $definition->afterCreate($record, $context);

            return $record;
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     * @param  array<string, mixed>  $data  已验证的局部修改属性
     * @return TModel
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function update(
        CrudDefinition $definition,
        int|string $id,
        array $data,
        CrudContext $context,
    ): Model {
        $modelClass = $this->assertModelClass($definition);
        $this->assertClientKeys($data, $definition->updateFields(), 'update');

        if ($data === []) {
            CrudValidation::throw([
                'data' => ['Update payload must not be empty.'],
            ]);
        }

        return $this->onModelConnection($modelClass, function () use ($definition, $id, $data, $context, $modelClass): Model {
            $record = $this->findForWrite($definition, CrudOperation::Update, $id, $context);
            $this->authorize($context, 'update', $record);

            $final = $definition->beforeUpdate($record, $data, $context);
            $this->assertHookResultIsArray($final, 'beforeUpdate');
            $this->assertFinalKeys(
                $final,
                $definition->updateFields(),
                $definition->serverFields(CrudOperation::Update),
                'beforeUpdate',
            );

            $this->assertFillable($record, $final);
            $record->fill($final);

            if ($record->save() === false) {
                throw new CrudPersistenceException(
                    $modelClass.'::save() returned false during update.'
                );
            }

            $definition->afterUpdate($record, $context);

            return $record;
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function delete(
        CrudDefinition $definition,
        int|string $id,
        CrudContext $context,
    ): void {
        $modelClass = $this->assertModelClass($definition);

        $this->onModelConnection($modelClass, function () use ($definition, $id, $context, $modelClass): void {
            $record = $this->findForWrite($definition, CrudOperation::Delete, $id, $context);
            $this->authorize($context, 'delete', $record);

            $definition->beforeDelete($record, $context);

            if ($record->delete() === false) {
                throw new CrudPersistenceException(
                    $modelClass.'::delete() returned false.'
                );
            }

            $definition->afterDelete($record, $context);
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     * @param  list<int|string>  $ids
     * @return int 实际删除条数
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function deleteMany(
        CrudDefinition $definition,
        array $ids,
        CrudContext $context,
    ): int {
        $modelClass = $this->assertModelClass($definition);
        $limits = $this->limits ?? QueryLimits::fromConfig();

        if ($ids === []) {
            CrudValidation::throw([
                'ids' => ['At least one id is required.'],
            ]);
        }

        if (count($ids) > $limits->maxBatchIds) {
            CrudValidation::throw([
                'ids' => ["At most {$limits->maxBatchIds} ids are allowed."],
            ]);
        }

        /** @var Model $keyProbe */
        $keyProbe = new $modelClass;
        $ids = $this->normalizeBatchIds($ids, $keyProbe);

        $this->authorize($context, 'deleteAny', $modelClass);

        return $this->onModelConnection($modelClass, function () use ($definition, $ids, $context, $modelClass): int {
            $query = $this->scopedQuery($definition, CrudOperation::DeleteMany, $context);
            $keyName = $query->getModel()->getKeyName();

            /** @var Collection<int, TModel> $records */
            $records = (clone $query)
                ->whereKey($ids)
                ->orderBy($keyName)
                ->lockForUpdate()
                ->get();

            if ($records->count() !== count($ids)) {
                throw (new ModelNotFoundException)->setModel($modelClass, $ids);
            }

            foreach ($records as $record) {
                $this->authorize($context, 'delete', $record);
            }

            foreach ($records as $record) {
                $definition->beforeDelete($record, $context);
            }

            $deleted = 0;
            foreach ($records as $record) {
                if ($record->delete() === false) {
                    throw new CrudPersistenceException(
                        $modelClass.'::delete() returned false during deleteMany.'
                    );
                }
                $definition->afterDelete($record, $context);
                $deleted++;
            }

            return $deleted;
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     * @return class-string<TModel>
     */
    private function assertModelClass(CrudDefinition $definition): string
    {
        $modelClass = $definition->modelClass();
        if (! is_string($modelClass) || ! is_a($modelClass, Model::class, true)) {
            throw new InvalidCrudDefinition(
                $definition::class.'::modelClass() must return an Eloquent model class-string.'
            );
        }

        return $modelClass;
    }

    /**
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     * @return Builder<TModel>
     */
    private function scopedQuery(
        CrudDefinition $definition,
        CrudOperation $operation,
        CrudContext $context,
    ): Builder {
        $modelClass = $this->assertModelClass($definition);
        $query = $definition->scope($operation, $context);
        $model = $query->getModel();

        if (! $model instanceof $modelClass) {
            throw new InvalidCrudDefinition(
                $definition::class.'::scope() must return a Builder for '.$modelClass.'.'
            );
        }

        // scope 与 modelClass 必须同一连接，否则事务包不住真实写入
        $expectedConnection = (new $modelClass)->getConnection()->getName();
        $actualConnection = $model->getConnection()->getName();
        if ($expectedConnection !== $actualConnection) {
            throw new InvalidCrudDefinition(
                $definition::class.'::scope() connection ['.$actualConnection
                .'] must match model connection ['.$expectedConnection.'].'
            );
        }

        // 将 scope 条件整体 nested，避免顶层 OR 与后续 whereKey/筛选错误组合
        $base = $query->toBase();
        if (($base->wheres ?? []) === []) {
            return $query;
        }

        $outer = $model->newQuery();
        $outer->getQuery()->from = $base->from;
        $outer->where(function (Builder $group) use ($base): void {
            $group->getQuery()->addNestedWhereQuery($base, 'and');
        });

        return $outer;
    }

    /**
     * 写入路径：scope + 主键 + 行锁，找不到统一 404。
     *
     * @template TModel of Model
     *
     * @param  CrudDefinition<TModel>  $definition
     * @return TModel
     */
    private function findForWrite(
        CrudDefinition $definition,
        CrudOperation $operation,
        int|string $id,
        CrudContext $context,
    ): Model {
        $modelClass = $definition->modelClass();
        $query = $this->scopedQuery($definition, $operation, $context);

        /** @var TModel|null $record */
        $record = (clone $query)->whereKey($id)->lockForUpdate()->first();
        if ($record === null) {
            throw (new ModelNotFoundException)->setModel($modelClass, [$id]);
        }

        return $record;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyReadView(Builder $query, ReadView $view): void
    {
        if ($view->columns !== ['*']) {
            $columns = $view->columns;
            $keyName = $query->getModel()->getKeyName();
            if (! in_array($keyName, $columns, true) && ! in_array('*', $columns, true)) {
                $columns[] = $keyName;
            }
            $query->select($columns);
        }

        if ($view->with !== []) {
            $query->with($view->with);
        }

        if ($view->withCount !== []) {
            $query->withCount($view->withCount);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function assertClientKeys(array $data, array $allowed, string $operation): void
    {
        $extra = array_values(array_diff(array_keys($data), $allowed));
        if ($extra !== []) {
            $fields = [];
            foreach ($extra as $key) {
                $fields[$key] = ["Field [{$key}] is not allowed for {$operation}."];
            }
            CrudValidation::throw($fields);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $clientFields
     * @param  list<string>  $serverFields
     */
    private function assertFinalKeys(
        array $data,
        array $clientFields,
        array $serverFields,
        string $hook,
    ): void {
        $allowed = array_values(array_unique([...$clientFields, ...$serverFields]));
        $extra = array_values(array_diff(array_keys($data), $allowed));
        if ($extra !== []) {
            throw new InvalidCrudDefinition(
                "{$hook} returned disallowed attributes: ".implode(', ', $extra).'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertFillable(Model $model, array $data): void
    {
        $fillable = $model->getFillable();
        if ($fillable === []) {
            // guarded=[] 全开时 getFillable 可能为空；用 isFillable 逐项检查
            foreach (array_keys($data) as $key) {
                if (! $model->isFillable($key)) {
                    throw new InvalidCrudDefinition(
                        $model::class." attribute [{$key}] is not fillable."
                    );
                }
            }

            return;
        }

        $missing = array_values(array_diff(array_keys($data), $fillable));
        if ($missing !== []) {
            throw new InvalidCrudDefinition(
                $model::class.' fillable must include: '.implode(', ', $missing).'.'
            );
        }
    }

    private function assertHookResultIsArray(mixed $result, string $hook): void
    {
        if (! is_array($result)) {
            throw new InvalidCrudDefinition("{$hook} must return an array of attributes.");
        }
    }

    /**
     * @template TReturn
     *
     * @param  class-string<Model>  $modelClass
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function onModelConnection(string $modelClass, callable $callback): mixed
    {
        /** @var Model $probe */
        $probe = new $modelClass;

        return $probe->getConnection()->transaction($callback);
    }

    /**
     * @param  class-string<Model>|Model  $abilityArguments
     */
    private function authorize(CrudContext $context, string $ability, Model|string $abilityArguments): void
    {
        $this->gate->forUser($context->actor)->authorize($ability, $abilityArguments);
    }

    /**
     * 按模型主键类型规范化批量 ID，拒绝布尔/浮点/数组及非法整数字符串。
     *
     * @param  list<mixed>  $ids
     * @return list<int|string>
     */
    private function normalizeBatchIds(array $ids, Model $model): array
    {
        $keyType = $model->getKeyType();
        $normalized = [];

        foreach ($ids as $index => $id) {
            if (is_bool($id) || is_array($id) || is_float($id) || $id === null) {
                CrudValidation::throw([
                    'ids' => ["ids[{$index}] has an invalid type."],
                ]);
            }

            if ($keyType === 'int' || $keyType === 'integer') {
                if (is_int($id)) {
                    if ($id < 1) {
                        CrudValidation::throw([
                            'ids' => ["ids[{$index}] must be a positive integer."],
                        ]);
                    }
                    $normalized[] = $id;

                    continue;
                }

                if (is_string($id) && preg_match('/^[1-9]\d*$/', $id) === 1) {
                    $normalized[] = (int) $id;

                    continue;
                }

                CrudValidation::throw([
                    'ids' => ["ids[{$index}] must be a positive integer."],
                ]);
            }

            if (! is_string($id) && ! is_int($id)) {
                CrudValidation::throw([
                    'ids' => ["ids[{$index}] has an invalid type."],
                ]);
            }

            $asString = (string) $id;
            if ($asString === '') {
                CrudValidation::throw([
                    'ids' => ["ids[{$index}] must not be empty."],
                ]);
            }
            $normalized[] = $asString;
        }

        if (count($normalized) !== count(array_unique($normalized, SORT_REGULAR))) {
            CrudValidation::throw([
                'ids' => ['Duplicate ids are not allowed.'],
            ]);
        }

        return array_values($normalized);
    }
}
