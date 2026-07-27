<?php

namespace App\Http\Resources\Fleet;

use App\Fleet\Models\VehicleDriverAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VehicleDriverAssignment */
final class AssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'organization_id' => $this->organization_id,
            'assignment_type' => $this->assignment_type,
            'exclusive' => $this->exclusive,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'reason' => $this->reason,
            'approved_by' => $this->approved_by,
            'handover_odometer_km' => $this->handover_odometer_km,
            'handover_fuel_level' => $this->handover_fuel_level,
            'keys_handed_over' => $this->keys_handed_over,
            'documents_handed_over' => $this->documents_handed_over,
            'condition_notes' => $this->condition_notes,
            'acknowledgement_required' => $this->acknowledgement_required,
            'acknowledged_at' => $this->acknowledged_at?->toISOString(),
            'status' => $this->status,
            'closed_at' => $this->closed_at?->toISOString(),
            'closure_reason' => $this->closure_reason,
            'record_version' => $this->record_version,
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'asset_number' => $this->vehicle->asset_number,
                'plate_number' => $this->vehicle->current_plate_number,
                'status' => $this->vehicle->status,
                'compliance' => $this->vehicle->relationLoaded('complianceRecords')
                    ? $this->vehicle->complianceRecords->map(fn ($record) => [
                        'document_type' => $record->document_type,
                        'expires_on' => $record->expires_on?->toDateString(),
                        'status' => $record->expires_on?->isPast() ? 'expired' : 'current',
                    ])->values()
                    : [],
            ]),
            'driver' => $this->whenLoaded('driver', fn () => [
                'id' => $this->driver->id,
                'employee_number' => $this->driver->employee_number,
                'full_name' => $this->driver->full_name,
                'status' => $this->driver->status,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
