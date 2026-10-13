<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Query;

use Valencio\LaravelToolkit\Crud\Exceptions\InvalidCrudDefinition;

/**
 * 服务端读取视图：列、固定关联、关联计数。不接受客户端 fields/include。
 */
final readonly class ReadView
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>|array<string, mixed>  $with
     * @param  list<string>|array<string, mixed>  $withCount
     */
    public function __construct(
        public array $columns = ['*'],
        public array $with = [],
        public array $withCount = [],
    ) {
        if ($this->columns === []) {
            throw new InvalidCrudDefinition('ReadView columns must not be empty.');
        }
    }
}
