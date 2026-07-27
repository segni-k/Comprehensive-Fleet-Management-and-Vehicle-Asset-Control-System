<?php

namespace App\Mobile\Models;

use App\Fleet\Models\Driver;
use App\Identity\Models\User;
use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DriverDeviceAssignment extends MobileModel
{
    protected $table = 'driver_device_assignments';

    protected $casts = [
        'effective_from' => 'immutable_datetime',
        'effective_to'   => 'immutable_datetime',
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

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return BelongsTo<User, $this> */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
