<?php

namespace App\Mobile\Models;

use App\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceTrustEvaluation extends MobileModel
{
    protected $table = 'device_trust_evaluations';

    protected $casts = [
        'app_version_compliant'   => 'boolean',
        'os_version_compliant'    => 'boolean',
        'encryption_ready'        => 'boolean',
        'secure_storage_ready'    => 'boolean',
        'local_db_ready'          => 'boolean',
        'sync_ready'              => 'boolean',
        'policy_compliant'        => 'boolean',
        'integrity_warnings'      => 'array',
        'blocking_reasons'        => 'array',
        'evaluated_at'            => 'immutable_datetime',
    ];

    /** @return BelongsTo<MobileDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }

    /** @return BelongsTo<User, $this> */
    public function evaluatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }
}
