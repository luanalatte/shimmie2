<?php

declare(strict_types=1);

namespace Shimmie2;

trait AuthorizesActions
{
    public bool $ignores_permissions = false;
    public function ignorePermissions(): self
    {
        $this->ignores_permissions = true;
        return $this;
    }

    public function can(string $ability): bool
    {
        if ($this->ignores_permissions) {
            return true;
        }

        return Ctx::$user->can($ability);
    }
}
