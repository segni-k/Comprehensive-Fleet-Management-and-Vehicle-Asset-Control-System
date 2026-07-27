<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

final class TransitionWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'transition_code' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:190'],
            'expected_record_version' => ['required', 'integer', 'min:1'],
            'context' => ['sometimes', 'array'],
            'context.amount' => ['sometimes', 'numeric', 'min:0'],
            'context.currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
