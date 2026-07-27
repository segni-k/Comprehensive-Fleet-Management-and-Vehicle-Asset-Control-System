<?php

namespace App\Geography\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Place extends GeographyModel
{
    protected $casts = [
        'name' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];

    /** @return BelongsTo<PlaceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PlaceCategory::class, 'place_category_id');
    }

    /** @return HasMany<LocationPolicyVersion, $this> */
    public function policies(): HasMany
    {
        return $this->hasMany(LocationPolicyVersion::class);
    }
}
