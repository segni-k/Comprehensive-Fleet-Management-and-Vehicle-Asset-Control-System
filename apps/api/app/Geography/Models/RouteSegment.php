<?php

namespace App\Geography\Models;

final class RouteSegment extends GeographyModel
{
    protected $casts = [
        'distance_km' => 'decimal:2',
        'mandatory_stop' => 'boolean',
    ];
}
