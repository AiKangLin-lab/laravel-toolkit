<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Tests\Crud\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * 测试 Policy：登录可列表/创建；仅本人可看/改/删。
 */
final class CrudWidgetPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function view(?Authenticatable $user, CrudWidget $widget): bool
    {
        return $this->owns($user, $widget);
    }

    public function create(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function update(?Authenticatable $user, CrudWidget $widget): bool
    {
        return $this->owns($user, $widget);
    }

    public function delete(?Authenticatable $user, CrudWidget $widget): bool
    {
        return $this->owns($user, $widget);
    }

    public function deleteAny(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    private function owns(?Authenticatable $user, CrudWidget $widget): bool
    {
        return $user !== null
            && (string) $user->getAuthIdentifier() === (string) $widget->owner_id;
    }
}
