<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * 包内 CRUD 查询测试用模型（隔离表，非业务）。
 */
final class CrudWidget extends Model
{
    protected $table = 'crud_widgets';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_id',
        'name',
        'status',
        'archived_at',
        'created_at',
    ];
}
