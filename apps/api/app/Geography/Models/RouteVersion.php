<?php

namespace App\Geography\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class RouteVersion extends GeographyModel
{
    protected $casts = [
        'preferred' => 'boolean',
        'estimated_distance_km' => 'decimal:2',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
    ];

    /** @return HasMany<RouteSegment, $this> */
    public function segments(): HasMany
    {
        return $this->hasMany(RouteSegment::class)->orderBy('sequence');
    }
}
