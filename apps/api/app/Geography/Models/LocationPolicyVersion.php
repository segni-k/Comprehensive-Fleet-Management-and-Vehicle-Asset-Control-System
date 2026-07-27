<?php

namespace App\Geography\Models;

final class LocationPolicyVersion extends GeographyModel
{
    protected $casts = [
        'verifier_required' => 'boolean',
        'qr_required' => 'boolean',
        'photo_required' => 'boolean',
        'offline_allowed' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
    ];
}
