<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class MfaVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'max:512'],
            'code' => ['required', 'string', 'regex:/^\d{6,12}$/'],
            'trust_session' => ['sometimes', 'boolean'],
        ];
    }
}
