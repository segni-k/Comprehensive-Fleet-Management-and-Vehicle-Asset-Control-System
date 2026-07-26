<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

final class HierarchyValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'child_organization_id' => ['required', 'string', 'size:26'],
            'proposed_parent_organization_id' => ['required', 'string', 'size:26'],
            'effective_at' => ['required', 'date'],
        ];
    }
}
