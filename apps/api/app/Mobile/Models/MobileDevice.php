<?php

namespace App\Mobile\Models;

use App\Fleet\Models\Driver;
use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class MobileDevice extends MobileModel
{
    protected $table = 'mobile_devices';

    protected $casts = [
        'capability_metadata'      => 'array',
        'first_seen_at'            => 'immutable_datetime',
        'last_seen_at'             => 'immutable_datetime',
        'last_sync_at'             => 'immutable_datetime',
        'last_trust_evaluated_at'  => 'immutable_datetime',
        'remote_actions_checked_at' => 'immutable_datetime',
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

    /** @return HasMany<DriverDeviceAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(DriverDeviceAssignment::class);
    }

    /** @return HasOne<DriverDeviceAssignment, $this> */
    public function activeAssignment(): HasOne
    {
        return $this->hasOne(DriverDeviceAssignment::class)
            ->where('status', 'active')
            ->latestOfMany('effective_from');
    }

    /** @return HasMany<DeviceTrustEvaluation, $this> */
    public function trustEvaluations(): HasMany
    {
        return $this->hasMany(DeviceTrustEvaluation::class);
    }

    /** @return HasMany<DeviceRemoteAction, $this> */
    public function remoteActions(): HasMany
    {
        return $this->hasMany(DeviceRemoteAction::class);
    }

    /** @return HasMany<MobileSyncSession, $this> */
    public function syncSessions(): HasMany
    {
        return $this->hasMany(MobileSyncSession::class);
    }

    /** @return HasMany<DeviceStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(DeviceStatusHistory::class);
    }

    public function isActive(): bool
    {
        return $this->lifecycle_state === 'active';
    }

    public function isTrusted(): bool
    {
        return in_array($this->trust_state, ['trusted', 'degraded'], true);
    }
}
