<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

final class ConfigureWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:120'],
            'version_number' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'array'], 'name.en' => ['required', 'string', 'max:190'],
            'process_type' => ['required', 'string', 'max:120'],
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'applicability_rules' => ['required', 'array'],
            'assignment_rules' => ['required', 'array'],
            'escalation_rules' => ['nullable', 'array'],
            'maker_checker_required' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'states' => ['required', 'array', 'min:2'],
            'states.*.code' => ['required', 'string', 'max:80', 'distinct'],
            'states.*.name' => ['required', 'array'],
            'states.*.state_type' => ['required', 'in:initial,review,correction,terminal'],
            'states.*.sort_order' => ['required', 'integer', 'min:1'],
            'states.*.is_initial' => ['required', 'boolean'],
            'states.*.is_terminal' => ['required', 'boolean'],
            'states.*.service_level' => ['nullable', 'array'],
            'transitions' => ['required', 'array', 'min:1'],
            'transitions.*.code' => ['required', 'string', 'max:80', 'distinct'],
            'transitions.*.from_state' => ['required', 'string', 'max:80'],
            'transitions.*.to_state' => ['required', 'string', 'max:80'],
            'transitions.*.required_permission' => ['required', 'string', 'exists:permissions,code'],
            'transitions.*.guard_rules' => ['sometimes', 'array'],
            'transitions.*.reason_required' => ['sometimes', 'boolean'],
            'transitions.*.maker_checker_required' => ['sometimes', 'boolean'],
            'transitions.*.delegation_allowed' => ['sometimes', 'boolean'],
        ];
    }
}
