<?php

namespace App\Fleet\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class DriverLicence extends FleetModel
{
    protected $casts = [
        'licence_number_encrypted' => 'encrypted',
        'issued_on' => 'immutable_date',
        'expires_on' => 'immutable_date',
        'verified_at' => 'immutable_datetime',
    ];

    /** @return BelongsToMany<DriverLicenceClass, $this> */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(
            DriverLicenceClass::class,
            'driver_licence_class_assignments',
        )->withPivot(['id', 'effective_from', 'effective_to']);
    }
}
