<?php

namespace App\Http\Controllers\Mobile;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Identity\Services\AuthorizationService;
use App\Mobile\Models\DeviceEnrollmentRequest;
use App\Mobile\Models\DriverDeviceAssignment;
use App\Mobile\Models\MobileDevice;
use App\Mobile\Services\DeviceAssignmentService;
use App\Mobile\Services\DeviceEnrollmentService;
use App\Mobile\Services\DeviceLifecycleService;
use App\Mobile\Services\DeviceTrustService;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class AdminDeviceController extends MobileController
{
    public function __construct(
        AuthorizationService $authorization,
        AuditService $audit,
        OutboxService $outbox,
        private readonly DeviceEnrollmentService $enrollment,
        private readonly DeviceLifecycleService $lifecycle,
        private readonly DeviceAssignmentService $assignments,
        private readonly DeviceTrustService $trust,
    ) {
        parent::__construct($authorization, $audit, $outbox);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string', 'size:26']]);
        $orgId = $data['organization_id'];
        $this->authorizeOrganization($request, 'mobile.device.view', $orgId);

        $base = DB::table('mobile_devices')->where('organization_id', $orgId);

        return response()->json(['data' => [
            'enrolled'       => (clone $base)->where('lifecycle_state', 'active')->count(),
            'pending'        => (clone $base)->where('enrollment_state', 'pending_approval')->count(),
            'assignments'    => DB::table('driver_device_assignments')->where('organization_id', $orgId)->where('status', 'active')->count(),
            'trust_warnings' => (clone $base)->whereIn('trust_state', ['degraded', 'untrusted'])->where('lifecycle_state', 'active')->count(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id'  => ['required', 'string', 'size:26'],
            'query'            => ['nullable', 'string', 'max:120'],
            'lifecycle_state'  => ['nullable', 'string', 'max:30'],
            'enrollment_state' => ['nullable', 'string', 'max:30'],
            'trust_state'      => ['nullable', 'string', 'max:30'],
            'driver_id'        => ['nullable', 'string', 'size:26'],
            'per_page'         => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorizeOrganization($request, 'mobile.device.view', $data['organization_id']);

        $devices = MobileDevice::query()
            ->with(['driver'])
            ->where('organization_id', $data['organization_id'])
            ->when($data['query'] ?? null, fn ($q, $v) => $q->where(fn ($nested) => $nested
                ->where('display_name', 'like', "%{$v}%")
                ->orWhere('installation_id', 'like', "%{$v}%")
                ->orWhere('stable_device_id', 'like', "%{$v}%")))
            ->when($data['lifecycle_state'] ?? null, fn ($q, $v) => $q->where('lifecycle_state', $v))
            ->when($data['enrollment_state'] ?? null, fn ($q, $v) => $q->where('enrollment_state', $v))
            ->when($data['trust_state'] ?? null, fn ($q, $v) => $q->where('trust_state', $v))
            ->when($data['driver_id'] ?? null, fn ($q, $v) => $q->where('driver_id', $v))
            ->orderByDesc('last_seen_at')
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data' => $devices->map(fn (MobileDevice $d) => $this->formatDevice($d)),
            'meta' => ['total' => $devices->total(), 'per_page' => $devices->perPage(), 'current_page' => $devices->currentPage()],
        ]);
    }

    public function show(Request $request, MobileDevice $mobileDevice): JsonResponse
    {
        $this->authorizeOrganization($request, 'mobile.device.view', $mobileDevice->organization_id, 'mobile_device', $mobileDevice->id);
        $mobileDevice->load(['driver', 'activeAssignment.driver', 'trustEvaluations' => fn ($q) => $q->orderByDesc('evaluated_at')->limit(5)]);

        $history = DB::table('device_status_history')
            ->where('mobile_device_id', $mobileDevice->id)
            ->orderByDesc('effective_at')
            ->limit(20)
            ->get();

        $remoteActions = DB::table('device_remote_actions')
            ->where('mobile_device_id', $mobileDevice->id)
            ->orderByDesc('requested_at')
            ->limit(10)
            ->get();

        return response()->json(['data' => array_merge(
            $this->formatDevice($mobileDevice),
            ['status_history' => $history, 'remote_actions' => $remoteActions],
        )]);
    }

    public function initiateEnrollment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
            'driver_id'       => ['required', 'string', 'size:26'],
        ]);
        $this->authorizeOrganization($request, 'mobile.device.enroll', $data['organization_id']);

        $result = $this->enrollment->initiateEnrollment(
            $data,
            $this->actor($request),
            $this->session($request),
            $request,
        );

        return response()->json(['data' => [
            'challenge_id'   => $result['challenge']->id,
            'enrollment_code' => $result['plaintext_code'],
            'expires_at'     => $result['challenge']->expires_at,
            'driver_id'      => $result['challenge']->driver_id,
        ]], 201);
    }

    public function pendingEnrollments(Request $request): JsonResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string', 'size:26']]);
        $this->authorizeOrganization($request, 'mobile.device.approve', $data['organization_id']);

        $requests = DeviceEnrollmentRequest::query()
            ->with(['device', 'driver', 'reviewedBy'])
            ->where('organization_id', $data['organization_id'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $requests]);
    }

    public function approveEnrollment(Request $request, DeviceEnrollmentRequest $enrollmentRequest): JsonResponse
    {
        $this->authorizeOrganization($request, 'mobile.device.approve', $enrollmentRequest->organization_id);

        $device = $this->enrollment->approveEnrollment(
            $enrollmentRequest,
            $this->actor($request),
            $this->session($request),
            $request,
        );

        return response()->json(['data' => $this->formatDevice($device)]);
    }

    public function rejectEnrollment(Request $request, DeviceEnrollmentRequest $enrollmentRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->authorizeOrganization($request, 'mobile.device.reject', $enrollmentRequest->organization_id);

        $updated = $this->enrollment->rejectEnrollment(
            $enrollmentRequest,
            $data,
            $this->actor($request),
            $this->session($request),
            $request,
        );

        return response()->json(['data' => $updated]);
    }

    public function suspend(Request $request, MobileDevice $mobileDevice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->authorizeOrganization($request, 'mobile.device.suspend', $mobileDevice->organization_id);

        return response()->json(['data' => $this->formatDevice(
            $this->lifecycle->suspend($mobileDevice, $data, $this->actor($request), $this->session($request), $request)
        )]);
    }

    public function reactivate(Request $request, MobileDevice $mobileDevice): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->authorizeOrganization($request, 'mobile.device.activate', $mobileDevice->organization_id);

        return response()->json(['data' => $this->formatDevice(
            $this->lifecycle->reactivate($mobileDevice, $data, $this->actor($request), $this->session($request), $request)
        )]);
    }

    public function revoke(Request $request, MobileDevice $mobileDevice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->authorizeOrganization($request, 'mobile.device.revoke', $mobileDevice->organization_id);

        return response()->json(['data' => $this->formatDevice(
            $this->lifecycle->revoke($mobileDevice, $data, $this->actor($request), $this->session($request), $request)
        )]);
    }

    public function retire(Request $request, MobileDevice $mobileDevice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->authorizeOrganization($request, 'mobile.device.retire', $mobileDevice->organization_id);

        return response()->json(['data' => $this->formatDevice(
            $this->lifecycle->retire($mobileDevice, $data, $this->actor($request), $this->session($request), $request)
        )]);
    }

    public function assign(Request $request, MobileDevice $mobileDevice): JsonResponse
    {
        $data = $request->validate([
            'driver_id'       => ['required', 'string', 'size:26'],
            'assignment_type' => ['nullable', 'string', 'in:primary,temporary,replacement'],
            'reason'          => ['nullable', 'string', 'max:500'],
            'effective_from'  => ['nullable', 'date'],
        ]);
        $this->authorizeOrganization($request, 'mobile.device.assign', $mobileDevice->organization_id);

        $assignment = $this->assignments->assign(
            $mobileDevice, $data,
            $this->actor($request), $this->session($request), $request,
        );

        return response()->json(['data' => $assignment], 201);
    }

    public function endAssignment(Request $request, DriverDeviceAssignment $driverDeviceAssignment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->authorizeOrganization($request, 'mobile.device.assign', $driverDeviceAssignment->organization_id);

        return response()->json(['data' => $this->assignments->endAssignment(
            $driverDeviceAssignment, $data,
            $this->actor($request), $this->session($request), $request,
        )]);
    }

    public function requestRemoteAction(Request $request, MobileDevice $mobileDevice): JsonResponse
    {
        $data = $request->validate([
            'action_type' => ['required', 'string', 'in:sign_out,cache_reset,full_sync,force_update,re_enroll'],
            'reason'      => ['nullable', 'string', 'max:500'],
        ]);

        $permMap = [
            'sign_out'    => 'mobile.device.remote_sign_out',
            'cache_reset' => 'mobile.device.cache_reset',
            'full_sync'   => 'mobile.device.view',
            'force_update' => 'mobile.device.suspend',
            're_enroll'   => 'mobile.device.revoke',
        ];

        $this->authorizeOrganization($request, $permMap[$data['action_type']], $mobileDevice->organization_id);

        $this->lifecycle->requestRemoteAction($mobileDevice, $data['action_type'], $data['reason'] ?? null, $this->actor($request));

        $this->audit->record(
            eventType: "device.remote_action.{$data['action_type']}",
            category: 'mobile_device',
            action: 'request_remote_action',
            outcome: 'success',
            subjectType: 'mobile_device',
            subjectId: $mobileDevice->id,
            organizationId: $mobileDevice->organization_id,
            actor: $this->actor($request),
            session: $this->session($request),
            reason: $data['reason'] ?? null,
            metadata: ['action_type' => $data['action_type']],
            request: $request,
        );

        return response()->json(['data' => ['queued' => true, 'action_type' => $data['action_type']]]);
    }

    public function reevaluateTrust(Request $request, MobileDevice $mobileDevice): JsonResponse
    {
        $this->authorizeOrganization($request, 'mobile.device.view', $mobileDevice->organization_id);
        $evaluation = $this->trust->evaluate($mobileDevice, $this->actor($request));
        return response()->json(['data' => $evaluation]);
    }

    public function syncStatus(Request $request, MobileDevice $mobileDevice): JsonResponse
    {
        $this->authorizeOrganization($request, 'mobile.sync.view', $mobileDevice->organization_id);

        $sessions = DB::table('mobile_sync_sessions')
            ->where('mobile_device_id', $mobileDevice->id)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        $cursors = DB::table('mobile_sync_cursors')
            ->where('mobile_device_id', $mobileDevice->id)
            ->get();

        $pendingCommands = DB::table('mobile_offline_commands')
            ->where('mobile_device_id', $mobileDevice->id)
            ->where('status', 'received')
            ->count();

        return response()->json(['data' => [
            'last_sync_at'    => $mobileDevice->last_sync_at,
            'sync_sessions'   => $sessions,
            'cursors'         => $cursors,
            'pending_commands' => $pendingCommands,
        ]]);
    }

    /** @return array<string,mixed> */
    private function formatDevice(MobileDevice $d): array
    {
        return [
            'id'               => $d->id,
            'display_name'     => $d->display_name,
            'platform'         => $d->platform,
            'manufacturer'     => $d->manufacturer,
            'model'            => $d->model,
            'os_version'       => $d->os_version,
            'app_version'      => $d->app_version,
            'enrollment_state' => $d->enrollment_state,
            'trust_state'      => $d->trust_state,
            'lifecycle_state'  => $d->lifecycle_state,
            'driver_id'        => $d->driver_id,
            'driver'           => $d->relationLoaded('driver') ? $d->driver : null,
            'organization_id'  => $d->organization_id,
            'first_seen_at'    => $d->first_seen_at,
            'last_seen_at'     => $d->last_seen_at,
            'last_sync_at'     => $d->last_sync_at,
            'device_id_suffix' => substr($d->stable_device_id, -8),
            'record_version'   => $d->record_version,
        ];
    }
}
