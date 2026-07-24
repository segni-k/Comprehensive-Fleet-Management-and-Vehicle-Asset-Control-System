<?php

namespace App\Platform\Authorization;

interface AuthorizationBoundary
{
    public function allows(string $permission, ?string $organizationId = null): bool;
}
