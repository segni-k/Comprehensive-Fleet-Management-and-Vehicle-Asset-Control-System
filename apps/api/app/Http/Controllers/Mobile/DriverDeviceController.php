<?php

namespace App\Http\Controllers\Mobile;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Identity\Services\AuthorizationService;
use App\Mobile\Models\DeviceRemoteAction;
use App\Mobile\Models\MobileDevice;
use App\Mobile\Services\DeviceEnrollmentService;
use App\Mobile\Services\DeviceTrustService;
use App\Mobile\Services\SynchronizationService;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DriverDeviceController extends MobileController
{
    public function __construct(
        AuthorizationService $authorization,
        AuditService $audit,
        OutboxService $outbox,
        private readonly DeviceEnrollmentService $enrollment,
        private readonly SynchronizationService $sync,
        private readonly DeviceTrustService $trust,
    ) {
        parent::__construct($authorization, $audit, $outbox);
    }

    /**
     * Driver claims an enrollment challenge to register their device.
     */
    public function claimChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_code' => ['required', 'string', 'min:8', 'max:64'],
            'stable_device_id' => ['required', 'string', 'max:255'],
            'installation_id'  => ['required', 'string', 'max:255'],
            'display_name'     => ['nullable', 'string', 'max:120'],
            'manufacturer'     => ['nullable', 'string', 'max:100'],
            'model'            => ['nullable', 'string', 'max:100'],
            'os_version'       => ['nullable', 'string', 'max:60'],
            'app_version'      => ['nullable', 'string', 'max:60'],
        ]);

        $enrollmentRequest = $this->enrollment->claimChallenge($data, $request);

        return response()->json(['data' => [
            'enrollment_request_id' => $enrollmentRequest->id,
            'device_id'             => $enrollmentRequest->mobile_device_id,
            'status'                => $enrollmentRequest->status,
            'message'               => 'Device registered. Awaiting administrator approval.',
        ]], 201);
    }

    /**
     * Driver polls current device status.
     */
    public function deviceStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'string', 'max:255'],
        ]);

        $device = MobileDevice::query()
            ->where('installation_id', $data['installation_id'])
            ->first();

        if ($device === null) {
            return response()->json(['data' => [
                'enrollment_state' => 'not_enrolled',
                'lifecycle_state'  => null,
                'trust_state'      => null,
            ]]);
        }

        // Collect pending actions for this device
        $pendingActions = DB::table('device_remote_actions')
            ->where('mobile_device_id', $device->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderBy('requested_at')
            ->get(['id', 'action_type'])
            ->toArray();

        return response()->json(['data' => [
            'device_id'        => $device->id,
            'enrollment_state' => $device->enrollment_state,
            'lifecycle_state'  => $device->lifecycle_state,
            'trust_state'      => $device->trust_state,
            'pending_actions'  => $pendingActions,
            'device_id_suffix' => substr($device->stable_device_id, -8),
        ]]);
    }

    /**
     * Driver registers or refreshes device metadata.
     */
    public function registerMetadata(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stable_device_id' => ['required', 'string', 'max:255'],
            'installation_id'  => ['required', 'string', 'max:255'],
            'os_version'       => ['nullable', 'string', 'max:60'],
            'app_version'      => ['nullable', 'string', 'max:60'],
            'push_token'       => ['nullable', 'string', 'max:255'],
        ]);

        $device = MobileDevice::query()
            ->where('installation_id', $data['installation_id'])
            ->where('stable_device_id', $data['stable_device_id'])
            ->first();

        if ($device === null) {
            return $this->problem('Device not found', 'DEVICE_NOT_FOUND', 404);
        }

        if ($device->lifecycle_state === 'revoked') {
            return $this->problem('Device revoked', 'DEVICE_REVOKED', 403);
        }

        $device->update([
            'os_version'            => $data['os_version'] ?? $device->os_version,
            'app_version'           => $data['app_version'] ?? $device->app_version,
            'push_token_reference'  => $data['push_token'] ?? $device->push_token_reference,
            'last_seen_at'          => now(),
        ]);

        // Re-evaluate trust after metadata update
        $this->trust->evaluate($device);

        return response()->json(['data' => ['updated' => true, 'trust_state' => $device->fresh()->trust_state]]);
    }

    /**
     * Initialize a sync session.
     */
    public function initSync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'installation_id'  => ['required', 'string', 'max:255'],
            'stable_device_id' => ['required', 'string', 'max:255'],
            'organization_id'  => ['required', 'string', 'size:26'],
            'session_type'     => ['nullable', 'string', 'in:full,incremental,upload_only'],
        ]);

        $device = $this->resolveActiveDevice($data['installation_id'], $data['stable_device_id']);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $result = $this->sync->initializeSession(
            $device,
            $data['session_type'] ?? 'incremental',
            $data['organization_id'],
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Upload offline commands.
     */
    public function uploadCommands(Request $request): JsonResponse
    {
        $data = $request->validate([
            'installation_id'  => ['required', 'string', 'max:255'],
            'stable_device_id' => ['required', 'string', 'max:255'],
            'organization_id'  => ['required', 'string', 'size:26'],
            'commands'         => ['required', 'array', 'min:1', 'max:50'],
            'commands.*.client_command_id' => ['required', 'string', 'max:80'],
            'commands.*.idempotency_key'   => ['required', 'string', 'max:80'],
            'commands.*.command_type'      => ['required', 'string', 'max:60'],
        ]);

        $device = $this->resolveActiveDevice($data['installation_id'], $data['stable_device_id']);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $results = $this->sync->receiveCommands($device, $data['commands'], $data['organization_id']);

        return response()->json(['data' => $results]);
    }

    /**
     * Acknowledge a remote action as executed.
     */
    public function acknowledgeAction(Request $request, string $actionId): JsonResponse
    {
        $data = $request->validate([
            'installation_id'  => ['required', 'string', 'max:255'],
            'stable_device_id' => ['required', 'string', 'max:255'],
            'organization_id'  => ['required', 'string', 'size:26'],
        ]);

        $device = $this->resolveActiveDevice($data['installation_id'], $data['stable_device_id']);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $this->sync->acknowledgeRemoteAction($device, $actionId, $data['organization_id']);

        return response()->json(['data' => ['acknowledged' => true]]);
    }

    /**
     * Retrieve the current device policy for this org.
     */
    public function policy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
        ]);

        $policy = DB::table('mobile_device_policy_versions')
            ->where('organization_id', $data['organization_id'])
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->first();

        return response()->json(['data' => $policy ? [
            'minimum_app_version'          => $policy->minimum_app_version,
            'minimum_os_version'           => $policy->minimum_os_version,
            'offline_access_hours'         => $policy->offline_access_hours,
            'sync_interval_minutes'        => $policy->sync_interval_minutes,
            'enrollment_challenge_minutes'  => $policy->enrollment_challenge_minutes,
        ] : null]);
    }

    private function resolveActiveDevice(string $installationId, string $stableDeviceId): MobileDevice|JsonResponse
    {
        $device = MobileDevice::query()
            ->where('installation_id', $installationId)
            ->where('stable_device_id', $stableDeviceId)
            ->first();

        if ($device === null) {
            return $this->problem('Device not found', 'DEVICE_NOT_FOUND', 404);
        }

        if ($device->lifecycle_state === 'revoked') {
            return $this->problem('Device revoked', 'DEVICE_REVOKED', 403);
        }

        if ($device->enrollment_state !== 'enrolled') {
            return $this->problem('Device not enrolled', 'DEVICE_NOT_ENROLLED', 403);
        }

        return $device;
    }
}
