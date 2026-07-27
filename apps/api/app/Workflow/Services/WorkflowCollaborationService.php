<?php

namespace App\Workflow\Services;

use App\Audit\Services\AuditService;
use App\Documents\Models\Document;
use App\Exceptions\BusinessRuleException;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Outbox\Services\OutboxService;
use App\Workflow\Models\WorkflowAssignment;
use App\Workflow\Models\WorkflowComment;
use App\Workflow\Models\WorkflowInstance;
use Illuminate\Support\Facades\DB;

final class WorkflowCollaborationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function comment(
        WorkflowInstance $instance,
        User $author,
        string $body,
        ?string $documentId,
    ): WorkflowComment {
        if ($documentId !== null && ! Document::query()
            ->whereKey($documentId)
            ->where('organization_id', $instance->organization_id)
            ->exists()) {
            throw new BusinessRuleException('WORKFLOW_ATTACHMENT_SCOPE_INVALID', 'The attachment is outside the workflow organization.');
        }

        return DB::transaction(function () use ($instance, $author, $body, $documentId): WorkflowComment {
            $comment = WorkflowComment::query()->create([
                'workflow_instance_id' => $instance->id,
                'author_user_id' => $author->id,
                'body' => $body,
                'document_id' => $documentId,
                'created_at' => now(),
            ]);
            $this->audit->record(
                'workflow.comment.created.succeeded', 'workflow', 'comment', 'succeeded',
                'workflow_instance', $instance->id, $instance->organization_id, $author,
                reason: 'Workflow comment added.',
                metadata: ['comment_id' => $comment->id, 'has_attachment' => $documentId !== null],
                workflowReference: $instance->id,
            );

            return $comment;
        });
    }

    public function reassign(
        WorkflowInstance $instance,
        string $assignedUserId,
        User $actor,
        UserSession $session,
        string $reason,
        int $expectedVersion,
    ): WorkflowAssignment {
        return DB::transaction(function () use ($instance, $assignedUserId, $actor, $session, $reason, $expectedVersion): WorkflowAssignment {
            /** @var WorkflowInstance $locked */
            $locked = WorkflowInstance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($locked->record_version !== $expectedVersion || $locked->status !== 'active') {
                throw new BusinessRuleException('WORKFLOW_STALE_ACTION', 'The workflow changed before reassignment.');
            }
            $current = $locked->assignments()->where('status', 'open')->latest('assigned_at')->first();
            if ($current === null) {
                throw new BusinessRuleException('WORKFLOW_ASSIGNMENT_UNAVAILABLE', 'No open assignment is available.');
            }
            $current->forceFill(['status' => 'reassigned', 'completed_at' => now()])->save();
            $assignment = $locked->assignments()->create([
                'assigned_user_id' => $assignedUserId,
                'required_permission' => $current->required_permission,
                'organization_id' => $locked->organization_id,
                'assigned_at' => now(),
                'due_at' => $current->due_at,
                'status' => 'open',
            ]);
            $locked->forceFill(['record_version' => $locked->record_version + 1])->save();
            $this->audit->record(
                'workflow.assignment.reassigned.succeeded', 'workflow', 'reassign', 'succeeded',
                'workflow_instance', $locked->id, $locked->organization_id, $actor, $session,
                $reason, ['assigned_user_id' => $current->assigned_user_id],
                ['assigned_user_id' => $assignedUserId], workflowReference: $locked->id,
            );
            $this->outbox->enqueue(
                'workflow.assignment.changed', 'workflow_instance', $locked->id,
                ['workflow_instance_id' => $locked->id, 'assignment_id' => $assignment->id],
                'workflow-reassignment:'.$assignment->id, $locked->organization_id,
            );

            return $assignment;
        });
    }

    public function escalateOverdue(int $limit = 100): int
    {
        $assignments = WorkflowAssignment::query()
            ->where('status', 'open')
            ->where('due_at', '<', now())
            ->orderBy('due_at')
            ->limit($limit)
            ->get();
        foreach ($assignments as $assignment) {
            DB::transaction(function () use ($assignment): void {
                $updated = WorkflowAssignment::query()
                    ->whereKey($assignment->id)
                    ->where('status', 'open')
                    ->update(['status' => 'escalated']);
                if ($updated !== 1) {
                    return;
                }
                $this->outbox->enqueue(
                    'workflow.assignment.escalated', 'workflow_instance', $assignment->workflow_instance_id,
                    ['workflow_instance_id' => $assignment->workflow_instance_id, 'assignment_id' => $assignment->id],
                    'workflow-escalation:'.$assignment->id, $assignment->organization_id,
                );
            });
        }

        return $assignments->count();
    }
}
