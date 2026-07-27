<?php

namespace App\Mobile\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Mobile\Models\DeviceStatusHistory;
use App\Mobile\Models\MobileDevice;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeviceLifecycleService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly DeviceTrustService $trust,
    ) {}

    /**
     * Suspend an active device.
     *
     * @param array<string,mixed> $data
     */
    public function suspend(
        MobileDevice $device,
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): MobileDevice {
        if ($device->lifecycle_state !== 'active') {
            throw new BusinessRuleException('DEVICE_NOT_ACTIVE');
        }

        return $this->transition($device, 'suspended', $data['reason'], $actor, $session, $request, 'device.lifecycle.suspended');
    }

    /**
     * Reactivate a suspended device.
     *
     * @param array<string,mixed> $data
     */
    public function reactivate(
        MobileDevice $device,
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): MobileDevice {
        if ($device->lifecycle_state !== 'suspended') {
            throw new BusinessRuleException('DEVICE_NOT_SUSPENDED');
        }

        return $this->transition($device, 'active', $data['reason'] ?? null, $actor, $session, $request, 'device.lifecycle.reactivated');
    }

    /**
     * Revoke a device — terminal state.
     *
     * @param array<string,mixed> $data
     */
    public function revoke(
        MobileDevice $device,
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): MobileDevice {
        if (in_array($device->lifecycle_state, ['revoked', 'retired', 'replaced'], true)) {
            throw new BusinessRuleException('DEVICE_ALREADY_TERMINAL');
        }

        $updated = $this->transition($device, 'revoked', $data['reason'], $actor, $session, $request, 'device.lifecycle.revoked');

        // Immediately request a remote sign-out
        $this->requestRemoteAction($updated, 'sign_out', $data['reason'], $actor);

        $this->outbox->dispatch('mobile.device.revoked', [
            'mobile_device_id' => $device->id,
            'organization_id'  => $device->organization_id,
            'driver_id'        => $device->driver_id,
            'reason'           => $data['reason'],
        ]);

        return $updated;
    }

    /**
     * Replace a device — marks current as replaced and returns it.
     *
     * @param array<string,mixed> $data
     */
    public function replace(
        MobileDevice $device,
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): MobileDevice {
        if (in_array($device->lifecycle_state, ['revoked', 'retired', 'replaced'], true)) {
            throw new BusinessRuleException('DEVICE_ALREADY_TERMINAL');
        }

        $updated = $this->transition($device, 'replaced', $data['reason'], $actor, $session, $request, 'device.lifecycle.replaced');

        $this->outbox->dispatch('mobile.device.replaced', [
            'mobile_device_id'            => $device->id,
            'replacement_initiated_for'   => $device->driver_id,
            'organization_id'             => $device->organization_id,
        ]);

        return $updated;
    }

    /**
     * Retire a device — graceful end-of-life.
     *
     * @param array<string,mixed> $data
     */
    public function retire(
        MobileDevice $device,
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): MobileDevice {
        if (in_array($device->lifecycle_state, ['revoked', 'retired', 'replaced'], true)) {
            throw new BusinessRuleException('DEVICE_ALREADY_TERMINAL');
        }

        return $this->transition($device, 'retired', $data['reason'], $actor, $session, $request, 'device.lifecycle.retired');
    }

    /**
     * Request a remote action against a device.
     */
    public function requestRemoteAction(
        MobileDevice $device,
        string $actionType,
        ?string $reason,
        User $actor,
    ): void {
        DB::table('device_remote_actions')->insert([
            'id'               => (string) Str::ulid(),
            'organization_id'  => $device->organization_id,
            'mobile_device_id' => $device->id,
            'action_type'      => $actionType,
            'status'           => 'pending',
            'reason'           => $reason,
            'requested_by'     => $actor->id,
            'requested_at'     => now(),
            'expires_at'       => now()->addDays(7),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function transition(
        MobileDevice $device,
        string $toState,
        ?string $reason,
        User $actor,
        ?UserSession $session,
        Request $request,
        string $eventType,
    ): MobileDevice {
        return DB::transaction(function () use ($device, $toState, $reason, $actor, $session, $request, $eventType): MobileDevice {
            $device->refresh()->lockForUpdate();

            $fromState = $device->lifecycle_state;

            $device->update([
                'lifecycle_state' => $toState,
                'record_version'  => DB::raw('record_version + 1'),
                'last_seen_at'    => now(),
            ]);

            DeviceStatusHistory::query()->create([
                'id'               => (string) Str::ulid(),
                'mobile_device_id' => $device->id,
                'status_type'      => 'lifecycle',
                'from_state'       => $fromState,
                'to_state'         => $toState,
                'reason'           => $reason,
                'changed_by'       => $actor->id,
                'effective_at'     => now(),
            ]);

            $this->audit->record(
                eventType: $eventType,
                category: 'mobile_device',
                action: "lifecycle_{$toState}",
                outcome: 'success',
                subjectType: 'mobile_device',
                subjectId: $device->id,
                organizationId: $device->organization_id,
                actor: $actor,
                session: $session,
                reason: $reason,
                before: ['lifecycle_state' => $fromState],
                after: ['lifecycle_state' => $toState],
                request: $request,
            );

            return $device->fresh();
        });
    }
}
