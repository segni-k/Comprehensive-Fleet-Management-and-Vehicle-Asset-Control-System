<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class AccessReview extends IdentityModel
{
    protected $fillable = [
        'organization_id',
        'requested_by',
        'reviewer_id',
        'review_type',
        'criteria',
        'due_at',
        'completed_at',
        'status',
    ];

    protected $attributes = [
        'status' => 'open',
        'record_version' => 1,
    ];

    protected $casts = [
        'criteria' => 'array',
        'due_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    /** @return HasMany<AccessReviewItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AccessReviewItem::class);
    }
}
