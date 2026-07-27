<?php

namespace App\Mobile\Models;

use App\Fleet\Models\Driver;
use App\Identity\Models\User;
use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceEnrollmentRequest extends MobileModel
{
    protected $table = 'device_enrollment_requests';

    protected $casts = [
        'reviewed_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<MobileDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }

    /** @return BelongsTo<DeviceEnrollmentChallenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(DeviceEnrollmentChallenge::class, 'challenge_id');
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
