<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Exceptions;

use RuntimeException;

/**
 * save/delete 被模型事件取消等持久化流程失败（默认按 5xx 处理）。
 *
 * 预期业务禁止应在 before* 钩子中抛明确业务异常，而不是依赖 observer 返回 false。
 */
final class CrudPersistenceException extends RuntimeException
{
}
