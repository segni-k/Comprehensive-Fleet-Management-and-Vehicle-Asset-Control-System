<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['identifier' => ['required', 'string', 'max:190'], 'password' => ['required', 'string', 'max:1024']];
    }
}
