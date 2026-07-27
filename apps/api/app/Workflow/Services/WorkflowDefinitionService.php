<?php

namespace App\Workflow\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Identity\Models\User;
use App\Outbox\Services\OutboxService;
use App\Workflow\Models\WorkflowDefinition;
use Illuminate\Support\Facades\DB;

final class WorkflowDefinitionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor = null): WorkflowDefinition
    {
        return DB::transaction(function () use ($data, $actor): WorkflowDefinition {
            $definition = WorkflowDefinition::query()->create([
                'code' => $data['code'],
                'version_number' => $data['version_number'],
                'name' => $data['name'],
                'process_type' => $data['process_type'],
                'organization_id' => $data['organization_id'] ?? null,
                'applicability_rules' => $data['applicability_rules'],
                'assignment_rules' => $data['assignment_rules'],
                'escalation_rules' => $data['escalation_rules'] ?? null,
                'maker_checker_required' => $data['maker_checker_required'] ?? true,
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'status' => 'draft',
            ]);
            $statesByCode = [];
            foreach ($data['states'] as $state) {
                $created = $definition->states()->create($state);
                $statesByCode[$created->code] = $created->id;
            }
            foreach ($data['transitions'] as $transition) {
                if (! isset($statesByCode[$transition['from_state']], $statesByCode[$transition['to_state']])) {
                    throw new BusinessRuleException('WORKFLOW_STATE_REFERENCE_INVALID', 'A transition references an unknown state.');
                }
                $definition->transitions()->create([
                    'code' => $transition['code'],
                    'from_state_id' => $statesByCode[$transition['from_state']],
                    'to_state_id' => $statesByCode[$transition['to_state']],
                    'required_permission' => $transition['required_permission'],
                    'guard_rules' => $transition['guard_rules'] ?? [],
                    'reason_required' => $transition['reason_required'] ?? true,
                    'maker_checker_required' => $transition['maker_checker_required'] ?? true,
                    'delegation_allowed' => $transition['delegation_allowed'] ?? true,
                ]);
            }
            $this->audit->record(
                'workflow.definition.created.succeeded', 'workflow', 'create_definition', 'succeeded',
                'workflow_definition', $definition->id, $definition->organization_id, $actor,
                reason: 'Workflow definition draft created.',
                after: ['code' => $definition->code, 'version_number' => $definition->version_number, 'status' => 'draft'],
            );

            return $definition->load(['states', 'transitions']);
        });
    }

    public function publish(WorkflowDefinition $definition, int $expectedVersion, ?User $actor = null): WorkflowDefinition
    {
        return DB::transaction(function () use ($definition, $expectedVersion, $actor): WorkflowDefinition {
            /** @var WorkflowDefinition $locked */
            $locked = WorkflowDefinition::query()->with(['states', 'transitions'])->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft' || $locked->record_version !== $expectedVersion) {
                throw new BusinessRuleException('WORKFLOW_DEFINITION_CONFLICT', 'The workflow definition changed or is not publishable.');
            }
            if ($locked->states->where('is_initial', true)->count() !== 1 || $locked->states->where('is_terminal', true)->isEmpty() || $locked->transitions->isEmpty()) {
                throw new BusinessRuleException('WORKFLOW_DEFINITION_INCOMPLETE', 'One initial state, a terminal state, and transitions are required.');
            }
            $overlap = WorkflowDefinition::query()
                ->where('code', $locked->code)
                ->where('status', 'active')
                ->whereKeyNot($locked->id)
                ->where(function ($query) use ($locked): void {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>', $locked->effective_from);
                })
                ->exists();
            if ($overlap) {
                throw new BusinessRuleException('WORKFLOW_DEFINITION_OVERLAP', 'An overlapping active workflow definition already exists.');
            }
            $locked->forceFill(['status' => 'active', 'record_version' => $locked->record_version + 1])->save();
            $this->audit->record(
                'workflow.definition.published.succeeded', 'workflow', 'publish_definition', 'succeeded',
                'workflow_definition', $locked->id, $locked->organization_id, $actor,
                reason: 'Workflow definition independently published.',
                before: ['status' => 'draft', 'record_version' => $expectedVersion],
                after: ['status' => 'active', 'record_version' => $locked->record_version],
            );
            $this->outbox->enqueue(
                'workflow.definition.published', 'workflow_definition', $locked->id,
                ['workflow_definition_id' => $locked->id, 'code' => $locked->code, 'version_number' => $locked->version_number],
                'workflow-definition-published:'.$locked->id, $locked->organization_id,
            );

            return $locked->refresh()->load(['states', 'transitions']);
        });
    }
}
