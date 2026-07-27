<?php

namespace App\Fleet\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class Driver extends FleetModel
{
    protected $casts = [
        'phone_encrypted' => 'encrypted',
        'email_encrypted' => 'encrypted',
        'emergency_contact_encrypted' => 'encrypted',
        'hired_on' => 'immutable_date',
        'terminated_on' => 'immutable_date',
    ];

    /** @return HasMany<DriverLicence, $this> */
    public function licences(): HasMany
    {
        return $this->hasMany(DriverLicence::class);
    }

    /** @return HasMany<VehicleDriverAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleDriverAssignment::class);
    }
}
