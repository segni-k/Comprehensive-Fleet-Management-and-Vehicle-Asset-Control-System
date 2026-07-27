<?php

namespace App\Mobile\Models;

use App\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceStatusHistory extends MobileModel
{
    protected $table = 'device_status_history';

    public $timestamps = true;

    protected $casts = [
        'effective_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<MobileDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
