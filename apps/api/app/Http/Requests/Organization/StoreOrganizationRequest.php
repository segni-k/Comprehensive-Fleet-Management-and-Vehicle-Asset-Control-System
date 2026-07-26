<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type_id' => ['required', 'string', 'size:26', 'exists:organization_types,id'],
            'code' => ['required', 'string', 'max:80', 'unique:organizations,code'],
            'name' => ['required', 'array:en,om,am'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.om' => ['required', 'string', 'max:255'],
            'name.am' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'string', 'size:26', 'exists:organizations,id'],
            'effective_from' => ['required', 'date'],
        ];
    }
}
