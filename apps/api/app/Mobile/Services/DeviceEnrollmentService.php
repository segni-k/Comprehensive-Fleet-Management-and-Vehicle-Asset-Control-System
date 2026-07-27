<?php

namespace App\Mobile\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Fleet\Models\Driver;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Mobile\Models\DeviceEnrollmentChallenge;
use App\Mobile\Models\DeviceEnrollmentRequest;
use App\Mobile\Models\DeviceStatusHistory;
use App\Mobile\Models\MobileDevice;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DeviceEnrollmentService
{
    private const CHALLENGE_BYTES = 32;
    private const CHALLENGE_MINUTES = 15;

    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly DeviceTrustService $trust,
    ) {}

    /**
     * Step 1 (Admin): Generate a time-limited enrollment challenge.
     *
     * @param array<string,mixed> $data
     * @return array{challenge: DeviceEnrollmentChallenge, plaintext_code: string}
     */
    public function initiateEnrollment(
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): array {
        $organizationId = $data['organization_id'];
        $driverId       = $data['driver_id'];

        return DB::transaction(function () use ($organizationId, $driverId, $actor, $session, $request): array {
            $driver = Driver::query()->findOrFail($driverId);

            // Cancel any currently active challenge for this driver
            DeviceEnrollmentChallenge::query()
                ->where('driver_id', $driverId)
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            $plaintextCode = strtoupper(Str::random(self::CHALLENGE_BYTES / 2));
            $hash          = hash('sha256', $plaintextCode);

            $challenge = DeviceEnrollmentChallenge::query()->create([
                'id'              => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'driver_id'       => $driverId,
                'initiated_by'    => $actor->id,
                'challenge_hash'  => $hash,
                'status'          => 'active',
                'expires_at'      => now()->addMinutes(self::CHALLENGE_MINUTES),
            ]);

            $this->audit->record(
                eventType: 'device.enrollment.initiated',
                category: 'mobile_device',
                action: 'initiate_enrollment',
                outcome: 'success',
                subjectType: 'driver',
                subjectId: $driverId,
                organizationId: $organizationId,
                actor: $actor,
                session: $session,
                metadata: ['challenge_id' => $challenge->id, 'driver_id' => $driverId],
                request: $request,
            );

            $this->outbox->dispatch('mobile.enrollment.initiated', [
                'challenge_id'    => $challenge->id,
                'organization_id' => $organizationId,
                'driver_id'       => $driverId,
            ]);

            return ['challenge' => $challenge, 'plaintext_code' => $plaintextCode];
        });
    }

    /**
     * Step 2 (Device): Claim a challenge to register a device and create an enrollment request.
     *
     * @param array<string,mixed> $data
     */
    public function claimChallenge(
        array $data,
        Request $request,
    ): DeviceEnrollmentRequest {
        $plaintextCode = $data['enrollment_code'];
        $hash          = hash('sha256', $plaintextCode);

        return DB::transaction(function () use ($data, $hash, $request): DeviceEnrollmentRequest {
            /** @var DeviceEnrollmentChallenge|null $challenge */
            $challenge = DeviceEnrollmentChallenge::query()
                ->where('challenge_hash', $hash)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                throw new BusinessRuleException('ENROLLMENT_CHALLENGE_NOT_FOUND');
            }

            if ($challenge->expires_at->isPast()) {
                $challenge->update(['status' => 'expired']);
                throw new BusinessRuleException('ENROLLMENT_CHALLENGE_EXPIRED');
            }

            if ($challenge->organization_id !== ($data['organization_id'] ?? $challenge->organization_id)) {
                throw new BusinessRuleException('ENROLLMENT_ORGANIZATION_MISMATCH');
            }

            // Prevent duplicate installation claims
            $existingDevice = MobileDevice::query()
                ->where('installation_id', $data['installation_id'])
                ->first();

            if ($existingDevice !== null && $existingDevice->lifecycle_state !== 'revoked') {
                throw new BusinessRuleException('DEVICE_INSTALLATION_ALREADY_REGISTERED');
            }

            // Create or reuse the device record
            $device = $existingDevice ?? MobileDevice::query()->create([
                'id'               => (string) Str::ulid(),
                'organization_id'  => $challenge->organization_id,
                'driver_id'        => $challenge->driver_id,
                'stable_device_id' => $data['stable_device_id'],
                'installation_id'  => $data['installation_id'],
                'display_name'     => $data['display_name'] ?? 'Android Device',
                'platform'         => 'android',
                'manufacturer'     => $data['manufacturer'] ?? null,
                'model'            => $data['model'] ?? null,
                'os_version'       => $data['os_version'] ?? null,
                'app_version'      => $data['app_version'] ?? null,
                'enrollment_state' => 'challenge_claimed',
                'trust_state'      => 'untrusted',
                'lifecycle_state'  => 'pending',
                'first_seen_at'    => now(),
                'last_seen_at'     => now(),
            ]);

            // Mark challenge as claimed (single-use)
            $challenge->update([
                'status'               => 'claimed',
                'claimed_at'           => now(),
                'claimed_by_device_id' => $device->id,
            ]);

            // Record status history
            $this->recordStatusHistory($device->id, 'enrollment', null, 'challenge_claimed', 'Challenge claimed by device');

            // Create enrollment request awaiting admin approval
            $enrollmentRequest = DeviceEnrollmentRequest::query()->create([
                'id'               => (string) Str::ulid(),
                'organization_id'  => $challenge->organization_id,
                'mobile_device_id' => $device->id,
                'challenge_id'     => $challenge->id,
                'driver_id'        => $challenge->driver_id,
                'status'           => 'pending',
            ]);

            $device->update(['enrollment_state' => 'pending_approval']);

            $this->outbox->dispatch('mobile.enrollment.pending', [
                'enrollment_request_id' => $enrollmentRequest->id,
                'mobile_device_id'      => $device->id,
                'organization_id'       => $challenge->organization_id,
                'driver_id'             => $challenge->driver_id,
            ]);

            return $enrollmentRequest->load(['device', 'driver', 'challenge']);
        });
    }

    /**
     * Step 3a (Admin): Approve an enrollment request.
     */
    public function approveEnrollment(
        DeviceEnrollmentRequest $request,
        User $actor,
        ?UserSession $session,
        \Illuminate\Http\Request $httpRequest,
    ): MobileDevice {
        if (! $request->isPending()) {
            throw new BusinessRuleException('ENROLLMENT_REQUEST_NOT_PENDING');
        }

        // Maker-checker: the initiator of the challenge cannot approve
        $challenge = $request->challenge()->first();
        if ($challenge && $challenge->initiated_by === $actor->id) {
            throw new BusinessRuleException('ENROLLMENT_SELF_APPROVAL_PROHIBITED');
        }

        return DB::transaction(function () use ($request, $actor, $session, $httpRequest): MobileDevice {
            if ($request->record_version !== $request->fresh()->record_version) {
                throw new ConflictException('ENROLLMENT_REQUEST_STALE');
            }

            $request->update([
                'status'          => 'approved',
                'reviewed_by'     => $actor->id,
                'reviewed_at'     => now(),
                'record_version'  => DB::raw('record_version + 1'),
            ]);

            /** @var MobileDevice $device */
            $device = $request->device()->lockForUpdate()->first();
            $device->update([
                'enrollment_state' => 'enrolled',
                'lifecycle_state'  => 'active',
                'trust_state'      => 'untrusted', // trust computed separately
                'last_seen_at'     => now(),
                'record_version'   => DB::raw('record_version + 1'),
            ]);

            $this->recordStatusHistory($device->id, 'enrollment', 'pending_approval', 'enrolled', null, $actor->id, $request->id);
            $this->recordStatusHistory($device->id, 'lifecycle', 'pending', 'active', null, $actor->id);

            // Run initial trust evaluation
            $this->trust->evaluate($device, $actor);

            $this->audit->record(
                eventType: 'device.enrollment.approved',
                category: 'mobile_device',
                action: 'approve_enrollment',
                outcome: 'success',
                subjectType: 'mobile_device',
                subjectId: $device->id,
                organizationId: $device->organization_id,
                actor: $actor,
                session: $session,
                metadata: ['enrollment_request_id' => $request->id],
                request: $httpRequest,
            );

            $this->outbox->dispatch('mobile.enrollment.approved', [
                'mobile_device_id'      => $device->id,
                'enrollment_request_id' => $request->id,
                'driver_id'             => $device->driver_id,
                'organization_id'       => $device->organization_id,
            ]);

            return $device->fresh();
        });
    }

    /**
     * Step 3b (Admin): Reject an enrollment request.
     *
     * @param array<string,mixed> $data
     */
    public function rejectEnrollment(
        DeviceEnrollmentRequest $request,
        array $data,
        User $actor,
        ?UserSession $session,
        \Illuminate\Http\Request $httpRequest,
    ): DeviceEnrollmentRequest {
        if (! $request->isPending()) {
            throw new BusinessRuleException('ENROLLMENT_REQUEST_NOT_PENDING');
        }

        return DB::transaction(function () use ($request, $data, $actor, $session, $httpRequest): DeviceEnrollmentRequest {
            $request->update([
                'status'           => 'rejected',
                'rejection_reason' => $data['reason'],
                'reviewed_by'      => $actor->id,
                'reviewed_at'      => now(),
                'record_version'   => DB::raw('record_version + 1'),
            ]);

            $device = $request->device()->lockForUpdate()->first();
            $device->update([
                'enrollment_state' => 'rejected',
                'lifecycle_state'  => 'revoked',
                'record_version'   => DB::raw('record_version + 1'),
            ]);

            $this->recordStatusHistory($device->id, 'enrollment', 'pending_approval', 'rejected', $data['reason'], $actor->id);

            $this->audit->record(
                eventType: 'device.enrollment.rejected',
                category: 'mobile_device',
                action: 'reject_enrollment',
                outcome: 'success',
                subjectType: 'mobile_device',
                subjectId: $device->id,
                organizationId: $device->organization_id,
                actor: $actor,
                session: $session,
                reason: $data['reason'],
                metadata: ['enrollment_request_id' => $request->id],
                request: $httpRequest,
            );

            $this->outbox->dispatch('mobile.enrollment.rejected', [
                'mobile_device_id'      => $device->id,
                'enrollment_request_id' => $request->id,
                'driver_id'             => $device->driver_id,
                'reason'                => $data['reason'],
            ]);

            return $request->fresh();
        });
    }

    private function recordStatusHistory(
        string $deviceId,
        string $type,
        ?string $from,
        string $to,
        ?string $reason = null,
        ?string $changedBy = null,
        ?string $approvalRef = null,
    ): void {
        DeviceStatusHistory::query()->create([
            'id'                  => (string) Str::ulid(),
            'mobile_device_id'    => $deviceId,
            'status_type'         => $type,
            'from_state'          => $from,
            'to_state'            => $to,
            'reason'              => $reason,
            'changed_by'          => $changedBy,
            'approval_reference'  => $approvalRef,
            'effective_at'        => now(),
        ]);
    }
}
