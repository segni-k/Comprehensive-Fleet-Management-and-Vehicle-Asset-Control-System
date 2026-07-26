<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Identity\Models\AccessReview;
use App\Identity\Models\BreakGlassAccess;
use App\Identity\Models\Delegation;
use App\Identity\Models\IdentityAuditEvent;
use App\Identity\Models\Permission;
use App\Identity\Models\Role;
use App\Identity\Models\User;
use App\Identity\Models\UserRoleAssignment;
use App\Identity\Services\BreakGlassService;
use App\Identity\Services\DelegationService;
use App\Identity\Services\PasswordPolicyService;
use App\Identity\Services\RoleAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class GovernanceController extends Controller
{
    public function __construct(
        private readonly RoleAssignmentService $assignments,
        private readonly DelegationService $delegations,
        private readonly BreakGlassService $breakGlass,
        private readonly PasswordPolicyService $passwordPolicy,
    ) {}

    public function users(): JsonResponse
    {
        return response()->json(['data' => User::query()->latest()->paginate()]);
    }

    public function createUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login_identifier' => ['required', 'string', 'max:190', 'unique:users'],
            'employee_identifier' => ['nullable', 'string', 'max:120', 'unique:users'],
            'email' => ['nullable', 'email', 'max:254'],
            'name' => ['required', 'array'], 'name.en' => ['required', 'string', 'max:190'],
            'preferred_locale' => ['required', 'in:en,om,am'],
            'temporary_password' => ['required', 'string', 'max:1024'],
        ]);
        $this->passwordPolicy->assertAcceptable($data['temporary_password']);
        $email = isset($data['email']) ? mb_strtolower($data['email']) : null;
        $user = User::query()->create([
            'login_identifier' => mb_strtolower($data['login_identifier']),
            'employee_identifier' => $data['employee_identifier'] ?? null,
            'email' => $email,
            'email_lookup_hash' => $email ? hash('sha256', $email) : null,
            'name' => $data['name'],
            'preferred_locale' => $data['preferred_locale'],
            'password' => Hash::make($data['temporary_password']),
            'status' => 'invited',
            'must_change_password' => true,
        ]);

        return response()->json(['data' => $user], 201);
    }

    public function changeUserStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,disabled'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'record_version' => ['required', 'integer', 'min:1'],
        ]);
        abort_if($user->record_version !== $data['record_version'], 409);
        $user->forceFill([
            'status' => $data['status'], 'status_reason' => $data['reason'],
            'record_version' => $user->record_version + 1,
        ])->save();
        if ($data['status'] !== 'active') {
            $user->sessions()->whereNull('revoked_at')->update([
                'revoked_at' => now(), 'revocation_reason' => 'account_'.$data['status'],
            ]);
        }

        return response()->json(['data' => $user->refresh()]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permission::query()->orderBy('domain')->orderBy('code')->get()]);
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->with('permissions')->latest()->paginate()]);
    }

    public function createRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'array'], 'name.en' => ['required', 'string', 'max:190'],
            'description' => ['required', 'string', 'max:2000'],
            'permission_codes' => ['required', 'array'], 'permission_codes.*' => ['string', 'exists:permissions,code'],
            'effective_from' => ['required', 'date'],
            'is_privileged' => ['sometimes', 'boolean'],
        ]);
        $role = Role::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'],
            'effective_from' => $data['effective_from'],
            'is_privileged' => $data['is_privileged'] ?? false,
            'status' => 'draft',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('code', $data['permission_codes'])->pluck('id'));

        return response()->json(['data' => $role->load('permissions')], 201);
    }

    public function roleAssignments(): JsonResponse
    {
        return response()->json(['data' => UserRoleAssignment::query()->with(['user', 'role', 'scopeGrants'])->latest()->paginate()]);
    }

    public function requestAssignment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'], 'role_id' => ['required', 'exists:roles,id'],
            'organization_id' => ['required', 'exists:organizations,id'],
            'scope_mode' => ['required', 'in:current_node,node_and_descendants,selected_child,explicit_record'],
            'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'], 'scope_grants' => ['array'],
        ]);
        $assignment = $this->assignments->request(
            $request->user(), User::query()->whereKey($data['user_id'])->firstOrFail(), Role::query()->whereKey($data['role_id'])->firstOrFail(),
            $data['organization_id'], $data['scope_mode'], new \DateTimeImmutable($data['effective_from']),
            isset($data['effective_to']) ? new \DateTimeImmutable($data['effective_to']) : null,
            $data['reason'], $data['scope_grants'] ?? [],
        );

        return response()->json(['data' => $assignment], 201);
    }

    public function approveAssignment(Request $request, UserRoleAssignment $assignment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->assignments->approve($request->user(), $assignment, $data['reason'])]);
    }

    public function revokeAssignment(Request $request, UserRoleAssignment $assignment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->assignments->revoke($request->user(), $assignment, $data['reason'])]);
    }

    public function delegations(): JsonResponse
    {
        return response()->json(['data' => Delegation::query()->with(['delegator', 'delegatee', 'permissions', 'scopeGrants'])->latest()->paginate()]);
    }

    public function requestDelegation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delegatee_user_id' => ['required', 'exists:users,id'],
            'source_assignment_id' => ['required', 'exists:user_role_assignments,id'],
            'permission_codes' => ['required', 'array', 'min:1'], 'permission_codes.*' => ['string'],
            'effective_from' => ['required', 'date'], 'effective_to' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'], 'scope_grants' => ['array'],
        ]);
        $delegation = $this->delegations->create(
            $request->user(), User::query()->whereKey($data['delegatee_user_id'])->firstOrFail(),
            UserRoleAssignment::query()->whereKey($data['source_assignment_id'])->firstOrFail(), $data['permission_codes'],
            new \DateTimeImmutable($data['effective_from']), new \DateTimeImmutable($data['effective_to']),
            $data['reason'], $data['scope_grants'] ?? [],
        );

        return response()->json(['data' => $delegation], 201);
    }

    public function approveDelegation(Request $request, Delegation $delegation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->delegations->approve($request->user(), $delegation, $data['reason'])]);
    }

    public function revokeDelegation(Request $request, Delegation $delegation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->delegations->revoke($request->user(), $delegation, $data['reason'])]);
    }

    public function accessReviews(): JsonResponse
    {
        return response()->json(['data' => AccessReview::query()->with('items')->latest()->paginate()]);
    }

    public function breakGlassEvents(): JsonResponse
    {
        return response()->json(['data' => BreakGlassAccess::query()->latest()->paginate()]);
    }

    public function startBreakGlass(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'permission_codes' => ['required', 'array', 'min:1'], 'permission_codes.*' => ['string'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'minutes' => ['required', 'integer', 'min:1'],
        ]);
        $access = $this->breakGlass->start(
            $request->user(), $request->attributes->get('identity_session'), $data['permission_codes'],
            $data['reason'], $data['minutes'], $data['organization_id'] ?? null,
        );

        return response()->json(['data' => $access], 201);
    }

    public function reviewBreakGlass(Request $request, BreakGlassAccess $access): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:confirmed,escalated'],
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->breakGlass->review($request->user(), $access, $data['decision'], $data['notes'])]);
    }

    public function audit(): JsonResponse
    {
        return response()->json(['data' => IdentityAuditEvent::query()->latest('occurred_at')->paginate()]);
    }
}
