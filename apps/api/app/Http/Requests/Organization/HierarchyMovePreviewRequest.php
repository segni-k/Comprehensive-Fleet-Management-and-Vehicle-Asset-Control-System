<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

final class HierarchyMovePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'proposed_parent_organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'requested_effective_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
