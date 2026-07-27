<?php

namespace App\Geography\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class DistanceReferenceVersion extends GeographyModel
{
    protected $casts = [
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
    ];

    /** @return HasMany<DistanceReferenceLeg, $this> */
    public function legs(): HasMany
    {
        return $this->hasMany(DistanceReferenceLeg::class, 'version_id');
    }
}
