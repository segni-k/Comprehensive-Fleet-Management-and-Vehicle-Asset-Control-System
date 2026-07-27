<?php

namespace App\Documents\Models;

final class DocumentLink extends DocumentModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'document_id', 'linked_entity_type', 'linked_entity_id', 'purpose', 'linked_by',
    ];
}
