<?php

namespace App\Geography\Models;

final class DistanceReferenceLeg extends GeographyModel
{
    protected $casts = [
        'distance_km' => 'decimal:2',
        'tolerance_percent' => 'decimal:3',
        'directional' => 'boolean',
    ];
}
