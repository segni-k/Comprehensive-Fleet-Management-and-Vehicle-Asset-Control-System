<?php

namespace App\Fleet\Models;

final class FleetComplianceRecord extends FleetModel
{
    protected $casts = [
        'document_number_encrypted' => 'encrypted',
        'issued_on' => 'immutable_date',
        'expires_on' => 'immutable_date',
    ];

    protected $hidden = [
        'document_number_encrypted',
        'document_number_hash',
    ];
}
