<?php

namespace App\Http\Resources\Fleet;

use App\Fleet\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Driver */
final class DriverResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'employee_number' => $this->employee_number,
            'organization_id' => $this->organization_id,
            'full_name' => $this->full_name,
            'employment_status' => $this->employment_status,
            'status' => $this->status,
            'availability_status' => $this->availability_status,
            'hired_on' => $this->hired_on?->toDateString(),
            'terminated_on' => $this->terminated_on?->toDateString(),
            'record_version' => $this->record_version,
            'licences' => $this->whenLoaded('licences', fn () => $this->licences->map(fn ($licence) => [
                'id' => $licence->id,
                'issuing_authority' => $licence->issuing_authority,
                'issued_on' => $licence->issued_on?->toDateString(),
                'expires_on' => $licence->expires_on->toDateString(),
                'status' => $licence->status,
                'document_id' => $licence->document_id,
                'classes' => $licence->relationLoaded('classes') ? $licence->classes->map->only(['id', 'code', 'name']) : [],
            ])),
            'assignments' => AssignmentResource::collection($this->whenLoaded('assignments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
