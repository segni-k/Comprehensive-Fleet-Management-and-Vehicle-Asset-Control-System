<?php

namespace App\Organization\Models;

final class OrganizationSettingDefinition extends OrganizationModel
{
    protected $casts = [
        'validation_rules' => 'array',
        'inheritable' => 'boolean',
        'sensitive' => 'boolean',
    ];
}
