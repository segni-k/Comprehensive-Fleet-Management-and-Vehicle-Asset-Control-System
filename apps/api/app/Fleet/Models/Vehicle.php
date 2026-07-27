<?php

namespace App\Fleet\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Vehicle extends FleetModel
{
    protected $casts = [
        'purchase_date' => 'immutable_date',
        'commissioned_on' => 'immutable_date',
        'retired_at' => 'immutable_datetime',
        'purchase_value' => 'decimal:2',
        'baseline_odometer_km' => 'decimal:1',
        'current_odometer_km' => 'decimal:1',
    ];

    /** @return BelongsTo<VehicleClass, $this> */
    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    /** @return BelongsTo<VehicleManufacturer, $this> */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(VehicleManufacturer::class);
    }

    /** @return BelongsTo<VehicleModel, $this> */
    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }

    /** @return HasMany<VehicleDriverAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleDriverAssignment::class);
    }

    /** @return HasMany<FleetComplianceRecord, $this> */
    public function complianceRecords(): HasMany
    {
        return $this->hasMany(FleetComplianceRecord::class, 'entity_id')
            ->where('entity_type', 'vehicle')
            ->where('status', 'current');
    }
}
