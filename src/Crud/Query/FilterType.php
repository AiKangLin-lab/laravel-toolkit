<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Query;

/**
 * 内置筛选规则类型。
 */
enum FilterType: string
{
    case Exact = 'exact';
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case In = 'in';
    case Range = 'range';
    case IsNull = 'is_null';
    case Custom = 'custom';
}
