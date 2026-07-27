<?php

namespace App\Documents\Models;

use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class DocumentType extends DocumentModel
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'allowed_mime_types', 'maximum_bytes', 'malware_scan_required',
        'retention_class', 'status',
    ];

    protected $casts = [
        'name' => 'array',
        'allowed_mime_types' => 'array',
        'maximum_bytes' => 'integer',
        'malware_scan_required' => 'boolean',
    ];

    protected static function newFactory(): DocumentTypeFactory
    {
        return DocumentTypeFactory::new();
    }
}
