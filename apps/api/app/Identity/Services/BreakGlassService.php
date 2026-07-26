<?php

namespace App\Identity\Services;

use App\Exceptions\BusinessRuleException;
use App\Identity\Models\BreakGlassAccess;
use App\Identity\Models\IdentitySecurityAlert;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use Illuminate\Support\Facades\DB;

final class BreakGlassService
{
    public function __construct(private readonly IdentityAuditService $audit)
    {
    }

    /** @param array<int, string> $permissionCodes */
    public function start(
        User $user,
        UserSession $session,
        array $permissionCodes,
        string $reason,
        int $minutes,
        ?string $organizationId = null,
    ): BreakGlassAccess {
        if ($session->user_id !== $user->id || $session->mfa_verified_at === null) {
            throw new BusinessRuleException('IDENTITY_BREAK_GLASS_MFA_REQUIRED', 'Recent MFA is required for emergency access.');
        }
        $maximum = (int) config('identity.break_glass.maximum_minutes');
        if ($minutes < 1 || $minutes > $maximum || trim($reason) === '' || $permissionCodes === []) {
            throw new BusinessRuleException('IDENTITY_BREAK_GLASS_REQUEST_INVALID', 'Reason, permissions, and a permitted duration are required.');
        }

        return DB::transaction(function () use ($user, $session, $permissionCodes, $reason, $minutes, $organizationId): BreakGlassAccess {
            $access = BreakGlassAccess::query()->create([
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'requested_session_id' => $session->id,
                'permission_codes' => array_values(array_unique($permissionCodes)),
                'reason' => $reason,
                'started_at' => now(),
                'expires_at' => now()->addMinutes($minutes),
                'status' => 'active',
            ]);
            IdentitySecurityAlert::query()->create([
                'alert_type' => 'break_glass_started',
                'severity' => 'critical',
                'user_id' => $user->id,
                'subject_type' => 'break_glass_access',
                'subject_id' => $access->id,
                'payload' => ['permission_codes' => $access->permission_codes, 'expires_at' => $access->expires_at],
                'status' => 'open',
            ]);
            $this->audit->record('identity.break_glass.started', 'break_glass_access', $access->id, 'succeeded', $user, $session, $organizationId, $reason, null, $access->toArray(), 'critical');

            return $access;
        });
    }

    public function end(User $actor, BreakGlassAccess $access, string $reason): BreakGlassAccess
    {
        if ($access->status !== 'active') {
            throw new BusinessRuleException('IDENTITY_BREAK_GLASS_NOT_ACTIVE', 'Emergency access is not active.');
        }
        $access->forceFill([
            'status' => 'ended', 'ended_at' => now(), 'ended_by' => $actor->id,
            'record_version' => $access->record_version + 1,
        ])->save();
        $this->audit->record('identity.break_glass.ended', 'break_glass_access', $access->id, 'succeeded', $actor, null, $access->organization_id, $reason, null, ['status' => 'ended'], 'critical');

        return $access->refresh();
    }

    public function review(User $reviewer, BreakGlassAccess $access, string $decision, string $notes): BreakGlassAccess
    {
        if ($reviewer->id === $access->user_id || ! in_array($decision, ['confirmed', 'escalated'], true)) {
            throw new BusinessRuleException('IDENTITY_BREAK_GLASS_REVIEW_INVALID', 'An independent valid review is required.');
        }
        $access->forceFill([
            'reviewed_by' => $reviewer->id, 'review_decision' => $decision,
            'review_notes' => $notes, 'reviewed_at' => now(),
            'record_version' => $access->record_version + 1,
        ])->save();
        $this->audit->record('identity.break_glass.reviewed', 'break_glass_access', $access->id, 'succeeded', $reviewer, null, $access->organization_id, $notes, null, ['review_decision' => $decision], 'critical');

        return $access->refresh();
    }
}
