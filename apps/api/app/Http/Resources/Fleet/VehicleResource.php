<?php

namespace App\Http\Resources\Fleet;

use App\Fleet\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vehicle */
final class VehicleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_number' => $this->asset_number,
            'vin' => $this->vin,
            'chassis_number' => $this->chassis_number,
            'engine_number' => $this->engine_number,
            'plate_number' => $this->current_plate_number,
            'registration_number' => $this->registration_number,
            'vehicle_category_id' => $this->vehicle_category_id,
            'vehicle_class_id' => $this->vehicle_class_id,
            'manufacturer_id' => $this->manufacturer_id,
            'vehicle_model_id' => $this->vehicle_model_id,
            'vehicle_trim_id' => $this->vehicle_trim_id,
            'owning_organization_id' => $this->owning_organization_id,
            'custodian_organization_id' => $this->custodian_organization_id,
            'fleet_unit_id' => $this->fleet_unit_id,
            'ownership_type' => $this->ownership_type,
            'model_year' => $this->model_year,
            'color' => $this->color,
            'fuel_type' => $this->fuel_type,
            'transmission' => $this->transmission,
            'capacity_kg' => $this->capacity_kg,
            'seating_capacity' => $this->seating_capacity,
            'acquisition_method' => $this->acquisition_method,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_value' => $this->purchase_value,
            'supplier_reference' => $this->supplier_reference,
            'funding_source' => $this->funding_source,
            'commissioned_on' => $this->commissioned_on?->toDateString(),
            'baseline_odometer_km' => $this->baseline_odometer_km,
            'current_odometer_km' => $this->current_odometer_km,
            'status' => $this->status,
            'retired_at' => $this->retired_at?->toISOString(),
            'record_version' => $this->record_version,
            'vehicle_class' => $this->whenLoaded('vehicleClass'),
            'manufacturer' => $this->whenLoaded('manufacturer'),
            'model' => $this->whenLoaded('vehicleModel'),
            'assignments' => AssignmentResource::collection($this->whenLoaded('assignments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
