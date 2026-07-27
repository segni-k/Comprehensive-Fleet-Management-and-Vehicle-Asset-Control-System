<?php

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asset_number' => ['required', 'string', 'max:80', 'unique:vehicles,asset_number'],
            'vin' => ['nullable', 'string', 'size:17', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/', 'unique:vehicles,vin'],
            'chassis_number' => ['required', 'string', 'min:5', 'max:100', 'regex:/^[A-Z0-9._ -]+$/i', 'unique:vehicles,chassis_number'],
            'engine_number' => ['nullable', 'string', 'min:3', 'max:100', 'regex:/^[A-Z0-9._ -]+$/i', 'unique:vehicles,engine_number'],
            'plate_number' => ['nullable', 'string', 'min:2', 'max:40', 'regex:/^[\pL\pN .-]+$/u', 'unique:vehicles,current_plate_number'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'vehicle_category_id' => ['required', 'string', 'size:26', 'exists:vehicle_categories,id'],
            'vehicle_class_id' => ['required', 'string', 'size:26', 'exists:vehicle_classes,id'],
            'manufacturer_id' => ['required', 'string', 'size:26', 'exists:vehicle_manufacturers,id'],
            'vehicle_model_id' => ['required', 'string', 'size:26', 'exists:vehicle_models,id'],
            'vehicle_trim_id' => ['nullable', 'string', 'size:26', 'exists:vehicle_trims,id'],
            'owning_organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'custodian_organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'fleet_unit_id' => ['nullable', 'string', 'size:26', 'exists:fleet_units,id'],
            'ownership_type' => ['required', Rule::in(['owned', 'leased', 'donated', 'transferred', 'other'])],
            'model_year' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'color' => ['nullable', 'string', 'max:60'],
            'fuel_type' => ['required', Rule::in(['petrol', 'diesel', 'electric', 'hybrid', 'other'])],
            'transmission' => ['required', Rule::in(['manual', 'automatic', 'cvt', 'other'])],
            'capacity_kg' => ['nullable', 'numeric', 'min:0'],
            'seating_capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'acquisition_method' => ['nullable', Rule::in(['purchase', 'lease', 'donation', 'transfer', 'other'])],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'purchase_value' => ['nullable', 'numeric', 'min:0'],
            'supplier_reference' => ['nullable', 'string', 'max:190'],
            'funding_source' => ['nullable', 'string', 'max:120'],
            'commissioned_on' => ['nullable', 'date'],
            'baseline_odometer_km' => ['required', 'numeric', 'min:0'],
            'plate' => ['nullable', 'array'],
            'plate.issuing_region' => ['nullable', 'string', 'max:100'],
            'plate.issued_on' => ['nullable', 'date'],
            'plate.expires_on' => ['nullable', 'date', 'after_or_equal:plate.issued_on'],
            'compliance' => ['sometimes', 'array', 'max:20'],
            'compliance.*.document_type' => ['required', Rule::in(['insurance', 'registration', 'roadworthiness', 'road_use', 'other'])],
            'compliance.*.document_number' => ['nullable', 'string', 'max:190'],
            'compliance.*.issued_on' => ['nullable', 'date'],
            'compliance.*.expires_on' => ['nullable', 'date'],
            'compliance.*.document_id' => ['nullable', 'string', 'size:26', 'exists:documents,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['asset_number', 'vin', 'chassis_number', 'engine_number', 'plate_number'] as $field) {
            if ($this->filled($field)) {
                $normalized[$field] = mb_strtoupper(trim((string) $this->input($field)));
            }
        }
        $this->merge($normalized);
    }
}
