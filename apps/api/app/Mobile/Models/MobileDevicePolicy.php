<?php

namespace App\Mobile\Models;

use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MobileDevicePolicy extends MobileModel
{
    protected $table = 'mobile_device_policy_versions';

    protected $casts = [
        'is_active'         => 'boolean',
        'effective_from'    => 'immutable_datetime',
        'effective_to'      => 'immutable_datetime',
        'approved_at'       => 'immutable_datetime',
        'additional_policy' => 'array',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
