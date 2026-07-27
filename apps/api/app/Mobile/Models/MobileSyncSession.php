<?php

namespace App\Mobile\Models;

use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MobileSyncSession extends MobileModel
{
    protected $table = 'mobile_sync_sessions';

    protected $casts = [
        'started_at'   => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<MobileDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
