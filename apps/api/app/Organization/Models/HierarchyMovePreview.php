<?php

namespace App\Organization\Models;

final class HierarchyMovePreview extends OrganizationModel
{
    protected $table = 'organization_hierarchy_move_previews';

    protected $casts = [
        'snapshot' => 'array',
        'requested_effective_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];
}
