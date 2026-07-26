<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\LoginRequest;
use App\Http\Requests\Identity\MfaVerifyRequest;
use App\Http\Requests\Identity\PasswordResetRequest;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthenticationService;
use App\Identity\Services\IdentityAuditService;
use App\Identity\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthenticationController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly SessionService $sessions,
        private readonly IdentityAuditService $audit,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json($this->authentication->login(
            $request->string('identifier')->toString(),
            $request->string('password')->toString(),
            $request,
        ));
    }

    public function verifyMfa(MfaVerifyRequest $request): JsonResponse
    {
        return response()->json($this->authentication->verifyMfa(
            $request->string('challenge_token')->toString(),
            $request->string('code')->toString(),
            $request->boolean('trust_session'),
            $request,
        ));
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate(['refresh_token' => ['required', 'string', 'max:512']]);

        return response()->json($this->sessions->rotate($validated['refresh_token'], $request));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var UserSession $session */
        $session = $request->attributes->get('identity_session');
        $this->sessions->revoke($session, 'user_logout');
        $this->audit->record('identity.session.revoked', 'session', $session->id, 'succeeded', $request->user(), $session, null, 'user_logout', null, null, 'normal', $request);

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var UserSession $session */
        $session = $request->attributes->get('identity_session');

        return response()->json([
            'data' => [
                'id' => $request->user()->id,
                'login_identifier' => $request->user()->login_identifier,
                'employee_identifier' => $request->user()->employee_identifier,
                'name' => $request->user()->name,
                'preferred_locale' => $request->user()->preferred_locale,
                'status' => $request->user()->status,
                'must_change_password' => $request->user()->must_change_password,
                'session' => $session->only(['id', 'auth_strength', 'trusted_until', 'access_expires_at']),
            ],
        ]);
    }

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $validated = $request->validate(['identifier' => ['required', 'string', 'max:190']]);

        return response()->json($this->authentication->requestPasswordReset($validated['identifier'], $request), 202);
    }

    public function resetPassword(PasswordResetRequest $request): JsonResponse
    {
        $this->authentication->resetPassword(
            $request->string('token')->toString(),
            $request->string('password')->toString(),
            $request,
        );

        return response()->json(['message' => 'Password updated. Sign in with the new password.']);
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->sessions()
            ->latest('last_seen_at')
            ->get()
            ->map(fn (UserSession $session): array => [
                'id' => $session->id,
                'auth_strength' => $session->auth_strength,
                'trusted_until' => $session->trusted_until,
                'last_seen_at' => $session->last_seen_at,
                'access_expires_at' => $session->access_expires_at,
                'refresh_expires_at' => $session->refresh_expires_at,
                'revoked_at' => $session->revoked_at,
                'revocation_reason' => $session->revocation_reason,
                'current' => $session->id === $request->attributes->get('identity_session')?->id,
            ])]);
    }

    public function revokeSession(Request $request, UserSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);
        $this->sessions->revoke($session, 'user_revoked');

        return response()->json(null, 204);
    }
}
