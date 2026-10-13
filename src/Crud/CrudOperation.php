<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud;

/**
 * CRUD 操作枚举。
 */
enum CrudOperation: string
{
    case List = 'list';
    case View = 'view';
    case Options = 'options';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case DeleteMany = 'delete_many';
}
