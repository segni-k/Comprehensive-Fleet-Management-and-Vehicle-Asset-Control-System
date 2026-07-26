<?php

namespace App\Identity\Services;

use App\Identity\Models\CredentialToken;
use App\Identity\Models\User;
use App\Identity\Models\UserMfaMethod;
use App\Identity\Models\UserSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthenticationService
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly TotpService $totp,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly IdentityAuditService $audit,
    ) {}

    /** @return array<string, mixed> */
    public function login(string $identifier, string $password, Request $request): array
    {
        $normalized = $this->normalize($identifier);
        $user = User::query()->where('login_identifier', $normalized)
            ->orWhere('email_lookup_hash', hash('sha256', $normalized))
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            $this->failedAttempt($user, $normalized, $request);
            throw new AuthenticationException('Invalid credentials.');
        }

        if ($user->locked_until?->isFuture() || $user->status !== 'active') {
            $this->recordAttempt($user, $normalized, 'account_unavailable', $request);
            throw new AuthenticationException('Invalid credentials.');
        }

        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
        $mfa = $user->mfaMethods()->where('status', 'active')->whereNotNull('verified_at')->exists();
        if ($mfa) {
            [$challenge, $token] = $this->credentialToken($user, 'mfa_challenge', 5, $request);
            $this->recordAttempt($user, $normalized, 'mfa_required', $request);

            return ['mfa_required' => true, 'challenge_token' => $challenge, 'expires_at' => $token->expires_at];
        }

        $tokens = $this->sessions->create($user, $request);
        $this->completeLogin($user, $tokens['session'], $request);

        return $tokens;
    }

    /** @return array<string, mixed> */
    public function verifyMfa(string $challenge, string $code, bool $trust, Request $request): array
    {
        return DB::transaction(function () use ($challenge, $code, $trust, $request): array {
            $token = CredentialToken::query()->with('user')->lockForUpdate()
                ->where('purpose', 'mfa_challenge')
                ->where('token_hash', hash('sha256', $challenge))
                ->first();

            if ($token === null || $token->used_at !== null || $token->expires_at->isPast()) {
                throw new AuthenticationException;
            }

            $method = $token->user->mfaMethods()->where('status', 'active')->whereNotNull('verified_at')->first();
            if ($method === null || ! $this->verifyMfaCode($method, $code)) {
                $this->recordAttempt($token->user, $token->user->login_identifier, 'mfa_failed', $request);
                throw ValidationException::withMessages(['code' => ['The verification code is invalid.']]);
            }

            $token->forceFill(['used_at' => now()])->save();
            $method->forceFill(['last_used_at' => now()])->save();
            $tokens = $this->sessions->create($token->user, $request, true, $trust);
            $this->completeLogin($token->user, $tokens['session'], $request);

            return $tokens;
        });
    }

    /** @return array{message:string,reset_token?:string} */
    public function requestPasswordReset(string $identifier, Request $request): array
    {
        $normalized = $this->normalize($identifier);
        $user = User::query()->where('login_identifier', $normalized)
            ->orWhere('email_lookup_hash', hash('sha256', $normalized))->first();
        $response = ['message' => 'If the account exists, reset instructions have been created.'];

        if ($user !== null && in_array($user->status, ['active', 'invited'], true)) {
            [$plain] = $this->credentialToken($user, 'password_reset', 30, $request);
            $this->audit->record('identity.password_reset.requested', 'user', $user->id, 'accepted', null, null, null, null, null, null, 'high', $request);
            if (app()->environment(['local', 'testing'])) {
                $response['reset_token'] = $plain;
            }
        }

        return $response;
    }

    public function resetPassword(string $plainToken, string $password, Request $request): User
    {
        return DB::transaction(function () use ($plainToken, $password, $request): User {
            $token = CredentialToken::query()->with('user')->lockForUpdate()
                ->where('purpose', 'password_reset')
                ->where('token_hash', hash('sha256', $plainToken))->first();
            if ($token === null || $token->used_at !== null || $token->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => ['The reset token is invalid or expired.']]);
            }

            $user = $token->user;
            $this->passwordPolicy->assertAcceptable($password, $user);
            $user->passwordHistory()->create([
                'password_hash' => $user->password,
                'created_at' => now(),
            ]);
            $user->forceFill([
                'password' => Hash::make($password),
                'password_changed_at' => now(),
                'password_expires_at' => now()->addDays((int) config('identity.password.expires_after_days')),
                'must_change_password' => false,
                'failed_login_count' => 0,
                'locked_until' => null,
                'status' => $user->status === 'invited' ? 'active' : $user->status,
                'record_version' => $user->record_version + 1,
            ])->save();
            $token->forceFill(['used_at' => now()])->save();
            $this->sessions->revokeAll($user, 'password_changed');
            $this->audit->record('identity.password.changed', 'user', $user->id, 'succeeded', $user, null, null, null, null, ['sessions_revoked' => true], 'high', $request);

            return $user;
        });
    }

    private function failedAttempt(?User $user, string $identifier, Request $request): void
    {
        if ($user !== null) {
            $failures = $user->failed_login_count + 1;
            $lock = $failures >= (int) config('identity.lockout.attempts');
            $user->forceFill([
                'failed_login_count' => $failures,
                'locked_until' => $lock ? now()->addMinutes((int) config('identity.lockout.minutes')) : null,
            ])->save();
        }
        $this->recordAttempt($user, $identifier, 'invalid_credentials', $request);
    }

    private function recordAttempt(?User $user, string $identifier, string $outcome, Request $request): void
    {
        DB::table('authentication_attempts')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user?->id,
            'identifier_hash' => hash('sha256', $identifier),
            'outcome' => $outcome,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
            'correlation_id' => $request->attributes->get('correlation_id'),
            'occurred_at' => now(),
        ]);
    }

    /** @return array{string,CredentialToken} */
    private function credentialToken(User $user, string $purpose, int $minutes, Request $request): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = $user->credentialTokens()->create([
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes($minutes),
            'requested_ip_hash' => hash('sha256', (string) $request->ip()),
        ]);

        return [$plain, $token];
    }

    private function verifyMfaCode(UserMfaMethod $method, string $code): bool
    {
        if ($this->totp->verify($method->secret, $code)) {
            return true;
        }
        $codes = $method->recovery_codes ?? [];
        foreach ($codes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$index]);
                $method->forceFill(['recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private function completeLogin(User $user, UserSession $session, Request $request): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $this->recordAttempt($user, $user->login_identifier, 'succeeded', $request);
        $this->audit->record('identity.authentication.succeeded', 'session', $session->id, 'succeeded', $user, $session, null, null, null, ['auth_strength' => $session->auth_strength], 'normal', $request);
    }

    private function normalize(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }
}
