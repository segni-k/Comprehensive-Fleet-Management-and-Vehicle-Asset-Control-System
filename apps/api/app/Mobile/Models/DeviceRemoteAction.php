<?php

namespace App\Mobile\Models;

use App\Identity\Models\User;
use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceRemoteAction extends MobileModel
{
    protected $table = 'device_remote_actions';

    protected $casts = [
        'requested_at'    => 'immutable_datetime',
        'expires_at'      => 'immutable_datetime',
        'acknowledged_at' => 'immutable_datetime',
        'executed_at'     => 'immutable_datetime',
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

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }
}
