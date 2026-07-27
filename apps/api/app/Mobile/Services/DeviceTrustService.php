<?php

namespace App\Mobile\Services;

use App\Identity\Models\User;
use App\Mobile\Models\DeviceTrustEvaluation;
use App\Mobile\Models\MobileDevice;
use App\Mobile\Models\MobileDevicePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeviceTrustService
{
    /**
     * Evaluate device trust based on current policy and device metadata.
     * Trust is a composite signal, not the sole authorization gate.
     */
    public function evaluate(MobileDevice $device, ?User $evaluatedBy = null): DeviceTrustEvaluation
    {
        $policy = $this->getActivePolicy($device->organization_id);

        $appVersionCompliant  = $this->checkVersion($device->app_version, $policy?->minimum_app_version);
        $osVersionCompliant   = $this->checkVersion($device->os_version, $policy?->minimum_os_version);
        $encryptionReady      = true;  // AES-GCM field-level encryption via EncryptedPayloadCodec
        $secureStorageReady   = true;  // expo-secure-store
        $localDbReady         = true;  // expo-sqlite
        $syncReady            = $device->lifecycle_state === 'active';
        $policyCompliant      = $appVersionCompliant && $osVersionCompliant;

        $blockingReasons  = [];
        $warnings         = [];

        if (! $appVersionCompliant && $policy?->minimum_app_version) {
            $blockingReasons[] = 'app_version_below_minimum';
        }

        if (! $osVersionCompliant && $policy?->minimum_os_version) {
            $warnings[] = 'os_version_below_minimum';
        }

        if ($device->lifecycle_state !== 'active') {
            $blockingReasons[] = 'device_not_active';
        }

        // Compute overall trust
        $overallTrust = match (true) {
            ! empty($blockingReasons) && $device->lifecycle_state === 'revoked' => 'revoked',
            ! empty($blockingReasons)                                            => 'untrusted',
            ! empty($warnings)                                                   => 'degraded',
            default                                                              => 'trusted',
        };

        return DB::transaction(function () use (
            $device, $evaluatedBy, $overallTrust,
            $appVersionCompliant, $osVersionCompliant,
            $encryptionReady, $secureStorageReady, $localDbReady, $syncReady, $policyCompliant,
            $warnings, $blockingReasons,
        ): DeviceTrustEvaluation {
            $evaluation = DeviceTrustEvaluation::query()->create([
                'id'                    => (string) Str::ulid(),
                'mobile_device_id'      => $device->id,
                'overall_trust_state'   => $overallTrust,
                'app_version_compliant' => $appVersionCompliant,
                'os_version_compliant'  => $osVersionCompliant,
                'encryption_ready'      => $encryptionReady,
                'secure_storage_ready'  => $secureStorageReady,
                'local_db_ready'        => $localDbReady,
                'sync_ready'            => $syncReady,
                'policy_compliant'      => $policyCompliant,
                'integrity_warnings'    => $warnings,
                'blocking_reasons'      => $blockingReasons,
                'evaluated_at'          => now(),
                'evaluated_by'          => $evaluatedBy?->id,
            ]);

            $device->update([
                'trust_state'             => $overallTrust,
                'last_trust_evaluated_at' => now(),
            ]);

            return $evaluation;
        });
    }

    private function getActivePolicy(string $organizationId): ?MobileDevicePolicy
    {
        return MobileDevicePolicy::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Version comparison: null policy version means no requirement.
     * Returns true when no minimum is set or device version meets/exceeds it.
     */
    private function checkVersion(?string $deviceVersion, ?string $minimumVersion): bool
    {
        if ($minimumVersion === null) {
            return true;
        }

        if ($deviceVersion === null) {
            return false;
        }

        return version_compare($deviceVersion, $minimumVersion, '>=');
    }
}
