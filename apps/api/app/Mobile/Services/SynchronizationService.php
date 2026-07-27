<?php

namespace App\Mobile\Services;

use App\Exceptions\BusinessRuleException;
use App\Mobile\Models\MobileDevice;
use App\Mobile\Models\MobileDevicePolicy;
use App\Mobile\Models\MobileSyncSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SynchronizationService
{
    /**
     * Initialize a synchronization session for a trusted active device.
     * Returns pending remote actions and the sync manifest.
     *
     * @return array<string,mixed>
     */
    public function initializeSession(
        MobileDevice $device,
        string $sessionType,
        string $organizationId,
    ): array {
        $this->assertDeviceAuthorized($device, $organizationId);

        $session = MobileSyncSession::query()->create([
            'id'               => (string) Str::ulid(),
            'mobile_device_id' => $device->id,
            'driver_id'        => $device->driver_id,
            'organization_id'  => $organizationId,
            'session_type'     => $sessionType,
            'status'           => 'started',
            'started_at'       => now(),
        ]);

        // Collect pending remote actions for device
        $pendingActions = DB::table('device_remote_actions')
            ->where('mobile_device_id', $device->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderBy('requested_at')
            ->get(['id', 'action_type', 'reason', 'requested_at'])
            ->toArray();

        // Collect dataset versions for this org
        $datasetVersions = $this->collectDatasetManifest($organizationId, $device->driver_id);

        $device->update(['last_seen_at' => now()]);

        return [
            'session_id'      => $session->id,
            'pending_actions' => $pendingActions,
            'manifest'        => $datasetVersions,
            'server_time'     => now()->toIso8601String(),
        ];
    }

    /**
     * Acknowledge a remote action as executed by the device.
     */
    public function acknowledgeRemoteAction(
        MobileDevice $device,
        string $actionId,
        string $organizationId,
    ): void {
        $this->assertDeviceAuthorized($device, $organizationId);

        $updated = DB::table('device_remote_actions')
            ->where('id', $actionId)
            ->where('mobile_device_id', $device->id)
            ->where('status', 'pending')
            ->update([
                'status'                 => 'acknowledged',
                'acknowledged_at'        => now(),
                'acknowledged_by_device' => $device->id,
                'updated_at'             => now(),
            ]);

        if ($updated === 0) {
            throw new BusinessRuleException('REMOTE_ACTION_NOT_FOUND');
        }
    }

    /**
     * Receive uploaded commands from the device.
     *
     * @param  array<int,array<string,mixed>>  $commands
     * @return array<int,array<string,string>>
     */
    public function receiveCommands(
        MobileDevice $device,
        array $commands,
        string $organizationId,
    ): array {
        $this->assertDeviceAuthorized($device, $organizationId);

        $results = [];

        foreach ($commands as $cmd) {
            $clientId      = $cmd['client_command_id'];
            $idempotencyKey = $cmd['idempotency_key'];

            // Idempotency: return existing result if already received
            $existing = DB::table('mobile_offline_commands')
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $results[] = ['client_command_id' => $clientId, 'status' => $existing->status];
                continue;
            }

            DB::table('mobile_offline_commands')->insert([
                'id'                => (string) Str::ulid(),
                'mobile_device_id'  => $device->id,
                'driver_id'         => $device->driver_id,
                'organization_id'   => $organizationId,
                'client_command_id' => $clientId,
                'idempotency_key'   => $idempotencyKey,
                'command_type'      => $cmd['command_type'],
                'status'            => 'received',
                'received_at'       => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $results[] = ['client_command_id' => $clientId, 'status' => 'received'];
        }

        return $results;
    }

    /**
     * Complete a sync session.
     */
    public function completeSession(
        string $sessionId,
        MobileDevice $device,
        bool $success,
        string $failureCategory = null,
    ): void {
        $session = MobileSyncSession::query()
            ->where('id', $sessionId)
            ->where('mobile_device_id', $device->id)
            ->first();

        if ($session === null) {
            return;
        }

        $session->update([
            'status'           => $success ? 'completed' : 'failed',
            'failure_category' => $failureCategory,
            'completed_at'     => now(),
        ]);

        if ($success) {
            $device->update(['last_sync_at' => now(), 'last_seen_at' => now()]);
        }
    }

    /**
     * Get cursor-based dataset manifest for incremental sync.
     *
     * @return array<string,mixed>
     */
    private function collectDatasetManifest(string $organizationId, ?string $driverId): array
    {
        // Approved offline datasets for M7
        $datasets = [
            'driver_profile',
            'vehicle_assignment',
            'operational_places',
            'approved_routes',
            'device_policy',
            'localization_metadata',
        ];

        $manifest = [];
        foreach ($datasets as $name) {
            $version = DB::table('mobile_dataset_versions')
                ->where('organization_id', $organizationId)
                ->where('dataset_name', $name)
                ->orderByDesc('version_number')
                ->first();

            $manifest[] = [
                'name'             => $name,
                'current_version'  => $version?->version_number ?? 0,
                'checksum'         => $version?->checksum,
                'record_count'     => $version?->record_count ?? 0,
                'last_published'   => $version?->published_at,
            ];
        }

        return $manifest;
    }

    private function assertDeviceAuthorized(MobileDevice $device, string $organizationId): void
    {
        if ($device->organization_id !== $organizationId) {
            throw new BusinessRuleException('DEVICE_ORGANIZATION_MISMATCH');
        }

        if ($device->lifecycle_state === 'revoked') {
            throw new BusinessRuleException('DEVICE_REVOKED');
        }

        if ($device->enrollment_state !== 'enrolled') {
            throw new BusinessRuleException('DEVICE_NOT_ENROLLED');
        }
    }
}
