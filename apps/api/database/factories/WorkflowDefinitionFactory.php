<?php

namespace Database\Factories;

use App\Workflow\Models\WorkflowDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkflowDefinition> */
final class WorkflowDefinitionFactory extends Factory
{
    protected $model = WorkflowDefinition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'TEST_'.fake()->unique()->regexify('[A-Z]{10}'),
            'version_number' => 1,
            'name' => ['en' => fake()->words(3, true)],
            'process_type' => 'neutral_record',
            'applicability_rules' => [],
            'assignment_rules' => ['permission' => 'workflow.approve'],
            'escalation_rules' => ['after_minutes' => 60],
            'maker_checker_required' => true,
            'effective_from' => now()->subDay(),
            'status' => 'draft',
        ];
    }
}
