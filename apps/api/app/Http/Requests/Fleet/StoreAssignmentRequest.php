<?php

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'string', 'size:26', 'exists:vehicles,id'],
            'driver_id' => ['required', 'string', 'size:26', 'exists:drivers,id'],
            'organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'assignment_type' => ['required', Rule::in(['permanent', 'temporary', 'pool', 'substitute'])],
            'exclusive' => ['required', 'boolean'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'approved_by' => ['nullable', 'string', 'size:26', 'exists:users,id'],
            'handover_odometer_km' => ['nullable', 'numeric', 'min:0'],
            'handover_fuel_level' => ['nullable', Rule::in(['empty', 'quarter', 'half', 'three_quarters', 'full'])],
            'keys_handed_over' => ['required', 'boolean'],
            'documents_handed_over' => ['required', 'boolean'],
            'condition_notes' => ['nullable', 'string', 'max:4000'],
            'acknowledgement_required' => ['required', 'boolean'],
        ];
    }
}
