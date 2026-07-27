<?php

namespace App\Documents\Models;

final class DocumentScanAttempt extends DocumentModel
{
    public $timestamps = false;

    protected $fillable = [
        'document_version_id', 'scanner_adapter', 'scanner_reference', 'outcome',
        'failure_class', 'safe_metadata', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'safe_metadata' => 'array',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];
}
