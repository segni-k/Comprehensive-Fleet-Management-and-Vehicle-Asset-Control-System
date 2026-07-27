<?php

namespace App\Geography\Models;

final class OperationalZone extends GeographyModel
{
    protected $casts = [
        'name' => 'array',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
