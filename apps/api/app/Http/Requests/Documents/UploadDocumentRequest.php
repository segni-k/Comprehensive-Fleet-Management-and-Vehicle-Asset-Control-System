<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

final class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480'],
            'document_type_code' => ['required', 'string', 'exists:document_types,code'],
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'owner_type' => ['required', 'string', 'max:100'],
            'owner_id' => ['required', 'string', 'size:26'],
            'category' => ['required', 'string', 'max:80'],
            'classification' => ['required', 'in:public,internal,confidential,restricted'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
