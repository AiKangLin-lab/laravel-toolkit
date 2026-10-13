<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * 当前操作者与追踪上下文（不可变）。
 *
 * 不携带 Request，不提供万能 attributes。
 */
final readonly class CrudContext
{
    public function __construct(
        public ?Authenticatable $actor = null,
        public ?string $traceId = null,
    ) {}
}
