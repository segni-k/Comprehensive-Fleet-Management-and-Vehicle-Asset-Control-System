<?php

namespace App\Documents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DocumentVersion extends DocumentModel
{
    public $timestamps = false;

    protected $fillable = [
        'document_id', 'version_number', 'supersedes_version_id', 'storage_disk',
        'storage_key', 'original_filename', 'media_type', 'size_bytes', 'checksum_algorithm',
        'checksum', 'uploaded_by', 'scan_status', 'trust_status', 'trusted_at', 'created_at',
    ];

    protected $hidden = ['storage_key'];

    protected $attributes = [
        'checksum_algorithm' => 'sha256', 'scan_status' => 'pending', 'trust_status' => 'quarantined',
    ];

    protected $casts = ['trusted_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<DocumentScanAttempt, $this> */
    public function scanAttempts(): HasMany
    {
        return $this->hasMany(DocumentScanAttempt::class);
    }
}
