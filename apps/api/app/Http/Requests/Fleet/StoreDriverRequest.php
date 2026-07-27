<?php

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'string', 'size:26', 'exists:users,id', 'unique:drivers,user_id'],
            'employee_number' => ['required', 'string', 'max:80', 'unique:drivers,employee_number'],
            'organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'full_name' => ['required', 'string', 'min:2', 'max:190'],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'emergency_contact' => ['nullable', 'string', 'max:500'],
            'employment_status' => ['required', Rule::in(['active', 'on_leave', 'suspended', 'inactive', 'terminated'])],
            'status' => ['required', Rule::in(['active', 'on_leave', 'suspended', 'inactive', 'terminated'])],
            'availability_status' => ['required', Rule::in(['available', 'assigned', 'unavailable', 'on_leave'])],
            'hired_on' => ['nullable', 'date', 'before_or_equal:today'],
            'licence' => ['required', 'array'],
            'licence.number' => ['required', 'string', 'min:3', 'max:190'],
            'licence.issuing_authority' => ['required', 'string', 'max:190'],
            'licence.issued_on' => ['nullable', 'date'],
            'licence.expires_on' => ['required', 'date', 'after:today'],
            'licence.status' => ['required', Rule::in(['pending_verification', 'verified'])],
            'licence.document_id' => ['nullable', 'string', 'size:26', 'exists:documents,id'],
            'licence.class_ids' => ['required', 'array', 'min:1'],
            'licence.class_ids.*' => ['string', 'size:26', 'distinct', 'exists:driver_licence_classes,id'],
            'qualifications' => ['sometimes', 'array', 'max:30'],
            'qualifications.*.code' => ['required', 'string', 'max:80'],
            'qualifications.*.title' => ['required', 'string', 'max:190'],
            'qualifications.*.issued_on' => ['nullable', 'date'],
            'qualifications.*.expires_on' => ['nullable', 'date'],
            'qualifications.*.document_id' => ['nullable', 'string', 'size:26', 'exists:documents,id'],
            'qualifications.*.notes' => ['nullable', 'string', 'max:2000'],
            'restrictions' => ['sometimes', 'array', 'max:30'],
            'restrictions.*.code' => ['required', 'string', 'max:80'],
            'restrictions.*.description' => ['required', 'string', 'max:2000'],
            'restrictions.*.starts_at' => ['required', 'date'],
            'restrictions.*.ends_at' => ['nullable', 'date'],
            'restrictions.*.reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
