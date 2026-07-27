<?php

namespace App\Geography\Models;

final class PlaceCategory extends GeographyModel
{
    protected $casts = [
        'name' => 'array',
        'allows_children' => 'boolean',
        'requires_coordinates' => 'boolean',
        'system_defined' => 'boolean',
    ];
}
