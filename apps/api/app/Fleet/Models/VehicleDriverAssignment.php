<?php

namespace App\Fleet\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VehicleDriverAssignment extends FleetModel
{
    protected $casts = [
        'exclusive' => 'boolean',
        'keys_handed_over' => 'boolean',
        'documents_handed_over' => 'boolean',
        'acknowledgement_required' => 'boolean',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'acknowledged_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'handover_odometer_km' => 'decimal:1',
    ];

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
