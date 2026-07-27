<?php

namespace App\Geography\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class RouteMaster extends GeographyModel
{
    protected $casts = [
        'name' => 'array',
        'directional' => 'boolean',
    ];

    /** @return HasMany<RouteVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RouteVersion::class);
    }
}
