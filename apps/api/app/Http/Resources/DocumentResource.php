<?php

namespace App\Http\Resources;

use App\Documents\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
final class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'document_type' => $this->whenLoaded('type', fn () => [
                'code' => $this->type->code,
                'name' => $this->type->name,
            ]),
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'category' => $this->category,
            'classification' => $this->classification,
            'status' => $this->status,
            'record_version' => $this->record_version,
            'expires_at' => $this->expires_at,
            'archived_at' => $this->archived_at,
            'current_version' => $this->whenLoaded('currentVersion', fn () => [
                'id' => $this->currentVersion->id,
                'version_number' => $this->currentVersion->version_number,
                'original_filename' => $this->currentVersion->original_filename,
                'media_type' => $this->currentVersion->media_type,
                'size_bytes' => $this->currentVersion->size_bytes,
                'checksum_algorithm' => $this->currentVersion->checksum_algorithm,
                'checksum' => $this->currentVersion->checksum,
                'scan_status' => $this->currentVersion->scan_status,
                'trust_status' => $this->currentVersion->trust_status,
                'created_at' => $this->currentVersion->created_at,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
