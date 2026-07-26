<?php

namespace App\Organization\Models;

final class HierarchyMoveRequest extends OrganizationModel
{
    protected $table = 'organization_hierarchy_move_requests';

    protected $casts = [
        'maker_checker_required' => 'boolean',
        'requested_effective_at' => 'immutable_datetime',
        'scheduled_at' => 'immutable_datetime',
    ];
}
