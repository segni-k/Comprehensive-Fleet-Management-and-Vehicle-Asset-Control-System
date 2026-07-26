<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOrganizationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,49}$/', 'unique:organization_types,code'],
            'name_key' => ['required', 'string', 'max:190'],
            'translations' => ['required', 'array:en,om,am'],
            'translations.en' => ['required', 'string', 'max:255'],
            'translations.om' => ['required', 'string', 'max:255'],
            'translations.am' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'between:0,10000'],
            'may_be_root' => ['required', 'boolean'],
            'effective_from' => ['required', 'date'],
        ];
    }
}
