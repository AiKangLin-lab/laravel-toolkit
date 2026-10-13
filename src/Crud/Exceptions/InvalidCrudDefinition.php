<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Exceptions;

use InvalidArgumentException;

/**
 * CRUD Definition / QuerySchema 等开发期配置错误。
 *
 * 表示包或项目声明不合法，属于 5xx 类程序错误，不是客户端输入错误。
 */
final class InvalidCrudDefinition extends InvalidArgumentException
{
}
