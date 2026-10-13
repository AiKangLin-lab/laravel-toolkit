<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;
use Valencio\LaravelToolkit\Crud\Query\QuerySchema;
use Valencio\LaravelToolkit\Crud\Query\ReadView;

/**
 * 模块 CRUD 声明基类。子类不得保存每次请求的用户/输入/Builder 状态。
 *
 * @template TModel of Model
 */
abstract class CrudDefinition
{
    /**
     * @return class-string<TModel>
     */
    abstract public function modelClass(): string;

    /**
     * 数据范围查询：仅 where/whereHas 等，不含客户端筛选与排序。
     *
     * @return Builder<TModel>
     */
    abstract public function scope(CrudOperation $operation, CrudContext $context): Builder;

    abstract public function querySchema(): QuerySchema;

    /**
     * @return list<string>
     */
    abstract public function createFields(): array;

    /**
     * @return list<string>
     */
    abstract public function updateFields(): array;

    /**
     * 读取视图。Options 必须由子类显式覆盖，否则视为定义错误。
     */
    public function readView(CrudOperation $operation): ReadView
    {
        if ($operation === CrudOperation::Options) {
            throw new InvalidCrudDefinition(
                static::class.' must override readView() for Options.'
            );
        }

        return new ReadView;
    }

    /**
     * @return list<string>
     */
    public function serverFields(CrudOperation $operation): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data, CrudContext $context): array
    {
        return $data;
    }

    /**
     * @param  TModel  $record
     */
    public function afterCreate(Model $record, CrudContext $context): void {}

    /**
     * @param  TModel  $record
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeUpdate(Model $record, array $data, CrudContext $context): array
    {
        return $data;
    }

    /**
     * @param  TModel  $record
     */
    public function afterUpdate(Model $record, CrudContext $context): void {}

    /**
     * @param  TModel  $record
     */
    public function beforeDelete(Model $record, CrudContext $context): void {}

    /**
     * @param  TModel  $record
     */
    public function afterDelete(Model $record, CrudContext $context): void {}
}
