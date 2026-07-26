<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class PasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:512'],
            'password' => ['required', 'string', 'confirmed', 'max:1024'],
        ];
    }
}
