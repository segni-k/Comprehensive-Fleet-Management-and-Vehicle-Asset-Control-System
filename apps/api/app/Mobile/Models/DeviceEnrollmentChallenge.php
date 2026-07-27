<?php

namespace App\Mobile\Models;

use App\Fleet\Models\Driver;
use App\Identity\Models\User;
use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceEnrollmentChallenge extends MobileModel
{
    protected $table = 'device_enrollment_challenges';

    protected $hidden = ['challenge_hash'];

    protected $casts = [
        'expires_at'  => 'immutable_datetime',
        'claimed_at'  => 'immutable_datetime',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }
}
