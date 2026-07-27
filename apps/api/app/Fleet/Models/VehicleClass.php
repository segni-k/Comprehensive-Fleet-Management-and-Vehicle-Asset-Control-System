<?php

namespace App\Fleet\Models;

final class VehicleClass extends FleetModel
{
    protected $casts = ['name' => 'array'];
}
