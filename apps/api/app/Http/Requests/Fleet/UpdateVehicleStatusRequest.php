<?php

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateVehicleStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['draft', 'active', 'suspended', 'under_maintenance', 'out_of_service', 'retired'])],
            'reason' => ['nullable', 'string', 'min:3', 'max:2000', 'required_if:status,suspended,out_of_service,retired'],
            'record_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
