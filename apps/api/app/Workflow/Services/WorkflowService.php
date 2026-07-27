<?php

namespace App\Workflow\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Identity\Models\RoleApprovalAuthority;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use App\Outbox\Services\OutboxService;
use App\Workflow\Models\WorkflowAction;
use App\Workflow\Models\WorkflowDefinition;
use App\Workflow\Models\WorkflowInstance;
use App\Workflow\Models\WorkflowTransition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class WorkflowService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    /** @param array<string, mixed> $context */
    public function start(
        WorkflowDefinition $definition,
        User $creator,
        string $organizationId,
        string $subjectType,
        string $subjectId,
        array $context,
    ): WorkflowInstance {
        if ($definition->status !== 'active' || $definition->effective_from->isFuture() || $definition->effective_to?->isPast()) {
            throw new BusinessRuleException('WORKFLOW_DEFINITION_INACTIVE', 'An active workflow definition is required.');
        }
        $initial = $definition->states()->where('is_initial', true)->first();
        if ($initial === null) {
            throw new BusinessRuleException('WORKFLOW_DEFINITION_INCOMPLETE', 'The workflow has no initial state.');
        }

        return DB::transaction(function () use ($definition, $creator, $organizationId, $subjectType, $subjectId, $context, $initial): WorkflowInstance {
            $instance = WorkflowInstance::query()->create([
                'workflow_definition_id' => $definition->id,
                'current_state_id' => $initial->id,
                'organization_id' => $organizationId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'created_by' => $creator->id,
                'context_snapshot' => $context,
                'due_at' => $this->dueAt($initial->service_level),
                'status' => $initial->is_terminal ? 'completed' : 'active',
            ]);
            if (! $initial->is_terminal) {
                $instance->assignments()->create([
                    'assigned_user_id' => $definition->assignment_rules['user_id'] ?? null,
                    'required_permission' => $definition->assignment_rules['permission'] ?? 'workflow.approve',
                    'organization_id' => $organizationId,
                    'assigned_at' => now(),
                    'due_at' => $instance->due_at,
                    'status' => 'open',
                ]);
            }
            $this->audit->record(
                'workflow.instance.started.succeeded', 'workflow', 'start', 'succeeded',
                'workflow_instance', $instance->id, $organizationId, $creator, null,
                'Workflow instance started.', null,
                ['state' => $initial->code, 'subject_type' => $subjectType, 'subject_id' => $subjectId],
                null, 'information', 'normal', $instance->id,
            );
            $this->outbox->enqueue(
                'workflow.instance.started', 'workflow_instance', $instance->id,
                ['workflow_instance_id' => $instance->id, 'state' => $initial->code],
                'workflow-started:'.$instance->id, $organizationId,
            );

            return $instance->load(['definition', 'currentState']);
        });
    }

    /** @param array<string, mixed> $decisionContext */
    public function transition(
        WorkflowInstance $instance,
        WorkflowTransition $transition,
        User $actor,
        UserSession $session,
        string $reason,
        string $idempotencyKey,
        int $expectedVersion,
        array $decisionContext = [],
    ): WorkflowAction {
        return DB::transaction(function () use ($instance, $transition, $actor, $session, $reason, $idempotencyKey, $expectedVersion, $decisionContext): WorkflowAction {
            /** @var WorkflowInstance $locked */
            $locked = WorkflowInstance::query()->with(['definition', 'currentState'])->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $existing = WorkflowAction::query()
                ->where('workflow_instance_id', $locked->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($locked->record_version !== $expectedVersion || $transition->from_state_id !== $locked->current_state_id || $locked->status !== 'active') {
                throw new BusinessRuleException('WORKFLOW_STALE_ACTION', 'The workflow state changed. Refresh before deciding.');
            }
            if ($transition->reason_required && mb_strlen(trim($reason)) < 3) {
                throw new BusinessRuleException('WORKFLOW_REASON_REQUIRED', 'A meaningful decision reason is required.');
            }
            if (($transition->maker_checker_required || $locked->definition->maker_checker_required) && $locked->created_by === $actor->id) {
                throw new BusinessRuleException('WORKFLOW_MAKER_CHECKER_REQUIRED', 'The workflow initiator cannot perform this decision.');
            }
            $authority = $this->authorization->resolveAuthority(
                $actor,
                $transition->required_permission,
                $locked->organization_id,
                $locked->subject_type,
                $locked->subject_id,
                $session,
            );
            if ($authority === null) {
                throw new BusinessRuleException('WORKFLOW_AUTHORITY_INSUFFICIENT', 'Current workflow authority is insufficient.');
            }
            if ($authority['delegation_id'] !== null && ! $transition->delegation_allowed) {
                throw new BusinessRuleException('WORKFLOW_DELEGATION_NOT_ALLOWED', 'Delegated authority is not allowed for this transition.');
            }
            $this->assertApprovalLimit($actor, $locked, $transition, $decisionContext);
            $toState = $locked->definition->states()->whereKey($transition->to_state_id)->firstOrFail();
            $before = ['state' => $locked->currentState->code, 'record_version' => $locked->record_version];
            $action = WorkflowAction::query()->create([
                'workflow_instance_id' => $locked->id,
                'transition_id' => $transition->id,
                'from_state_id' => $locked->current_state_id,
                'to_state_id' => $toState->id,
                'actor_user_id' => $actor->id,
                'actor_session_id' => $session->id,
                'role_assignment_id' => $authority['role_assignment_id'],
                'delegation_id' => $authority['delegation_id'],
                'authority_snapshot' => [
                    'permission' => $transition->required_permission,
                    'organization_id' => $locked->organization_id,
                    'role_assignment_id' => $authority['role_assignment_id'],
                    'delegation_id' => $authority['delegation_id'],
                    'captured_at' => now()->toISOString(),
                ],
                'context_snapshot' => $decisionContext,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'expected_record_version' => $expectedVersion,
                'acted_at' => now(),
            ]);
            $locked->forceFill([
                'current_state_id' => $toState->id,
                'due_at' => $this->dueAt($toState->service_level),
                'status' => $toState->is_terminal ? 'completed' : 'active',
                'record_version' => $locked->record_version + 1,
            ])->save();
            $locked->assignments()->where('status', 'open')->update([
                'completed_at' => now(),
                'status' => 'completed',
            ]);
            if (! $toState->is_terminal) {
                $locked->assignments()->create([
                    'assigned_user_id' => $locked->definition->assignment_rules['user_id'] ?? null,
                    'required_permission' => $locked->definition->assignment_rules['permission'] ?? $transition->required_permission,
                    'organization_id' => $locked->organization_id,
                    'assigned_at' => now(),
                    'due_at' => $locked->due_at,
                    'status' => 'open',
                ]);
            }
            $after = ['state' => $toState->code, 'record_version' => $locked->record_version];
            $this->audit->record(
                'workflow.'.$transition->code.'.succeeded', 'workflow', $transition->code,
                'succeeded', 'workflow_instance', $locked->id, $locked->organization_id,
                $actor, $session, $reason, $before, $after,
                ['permission' => $transition->required_permission], 'information', 'high', $locked->id,
            );
            $this->outbox->enqueue(
                'workflow.transition.completed', 'workflow_instance', $locked->id,
                ['workflow_instance_id' => $locked->id, 'transition' => $transition->code, 'to_state' => $toState->code],
                'workflow-action:'.$action->id, $locked->organization_id, null, $action->id,
            );

            return $action;
        });
    }

    /** @param array<string, mixed> $context */
    private function assertApprovalLimit(User $actor, WorkflowInstance $instance, WorkflowTransition $transition, array $context): void
    {
        if (! array_key_exists('amount', $context)) {
            return;
        }
        $amount = (string) $context['amount'];
        $authorityExists = RoleApprovalAuthority::query()
            ->whereHas('role.assignments', fn ($query) => $query
                ->where('user_id', $actor->id)
                ->where('organization_id', $instance->organization_id)
                ->where('status', 'active'))
            ->where('resource_type', $instance->subject_type)
            ->where('action', $transition->code)
            ->where('status', 'active')
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->where(fn ($query) => $query->whereNull('amount_limit')->orWhere('amount_limit', '>=', $amount))
            ->exists();
        if (! $authorityExists) {
            throw new BusinessRuleException('WORKFLOW_APPROVAL_LIMIT_EXCEEDED', 'The decision exceeds current approval authority.');
        }
    }

    /** @param array<string, mixed>|null $serviceLevel */
    private function dueAt(?array $serviceLevel): ?Carbon
    {
        $minutes = $serviceLevel['minutes'] ?? null;

        return is_int($minutes) && $minutes > 0 ? now()->addMinutes($minutes) : null;
    }
}
