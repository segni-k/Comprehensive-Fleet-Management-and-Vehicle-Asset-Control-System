<?php

namespace App\Documents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Document extends DocumentModel
{
    protected $fillable = [
        'document_type_id', 'organization_id', 'owner_type', 'owner_id', 'category',
        'classification', 'created_by', 'current_version_id', 'retention_until',
        'expires_at', 'archived_at', 'archived_by', 'archive_reason', 'status', 'record_version',
    ];

    protected $attributes = ['classification' => 'internal', 'status' => 'quarantined', 'record_version' => 1];

    protected $casts = [
        'retention_until' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'archived_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<DocumentType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /** @return HasMany<DocumentLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(DocumentLink::class);
    }
}
