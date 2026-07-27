<?php

namespace App\Fleet\Models;

final class VehicleCategory extends FleetModel
{
    protected $casts = ['name' => 'array'];
}
