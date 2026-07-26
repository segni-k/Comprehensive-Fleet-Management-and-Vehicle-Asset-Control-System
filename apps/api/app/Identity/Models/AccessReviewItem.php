<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccessReviewItem extends IdentityModel
{
    protected $fillable = [
        'access_review_id',
        'subject_type',
        'subject_id',
        'authority_snapshot',
        'decision',
        'review_notes',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'authority_snapshot' => 'array',
        'decided_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AccessReview, $this> */
    public function accessReview(): BelongsTo
    {
        return $this->belongsTo(AccessReview::class);
    }
}
