<?php

namespace App\Organization\Models;

final class OrganizationHistory extends OrganizationModel
{
    protected $table = 'organization_hierarchy_change_history';

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];
}
