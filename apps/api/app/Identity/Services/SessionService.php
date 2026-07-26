<?php

namespace App\Identity\Services;

use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SessionService
{
    /** @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,session:UserSession} */
    public function create(User $user, Request $request, bool $mfaVerified = false, bool $trusted = false): array
    {
        [$accessToken, $accessHash] = $this->token();
        [$refreshToken, $refreshHash] = $this->token();
        $accessMinutes = (int) config('identity.sessions.access_minutes');

        $session = UserSession::query()->create([
            'user_id' => $user->id,
            'access_token_hash' => $accessHash,
            'refresh_token_hash' => $refreshHash,
            'refresh_family_id' => (string) Str::ulid(),
            'refresh_sequence' => 1,
            'auth_strength' => $mfaVerified ? 'mfa' : 'password',
            'mfa_verified_at' => $mfaVerified ? now() : null,
            'trusted_until' => $trusted ? now()->addHours((int) config('identity.sessions.trusted_hours')) : null,
            'access_expires_at' => now()->addMinutes($accessMinutes),
            'refresh_expires_at' => now()->addDays((int) config('identity.sessions.refresh_days')),
            'last_seen_at' => now(),
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessMinutes * 60,
            'session' => $session,
        ];
    }

    public function authenticate(Request $request): UserSession
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            throw new AuthenticationException;
        }

        $session = UserSession::query()
            ->with('user')
            ->where('access_token_hash', hash('sha256', $token))
            ->first();

        if ($session === null || $session->revoked_at !== null || $session->access_expires_at->isPast()) {
            throw new AuthenticationException;
        }

        if ($session->user->status !== 'active') {
            throw new AuthenticationException;
        }

        $session->forceFill(['last_seen_at' => now()])->save();

        return $session;
    }

    /** @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,session:UserSession} */
    public function rotate(string $refreshToken, Request $request): array
    {
        return DB::transaction(function () use ($refreshToken, $request): array {
            $hash = hash('sha256', $refreshToken);
            $session = UserSession::query()->with('user')->lockForUpdate()
                ->where('refresh_token_hash', $hash)->first();

            if ($session === null) {
                throw new AuthenticationException;
            }

            if ($session->revoked_at !== null) {
                UserSession::query()->where('refresh_family_id', $session->refresh_family_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'revocation_reason' => 'refresh_token_replay']);
                throw new AuthenticationException;
            }

            if ($session->refresh_expires_at->isPast() || $session->user->status !== 'active') {
                $this->revoke($session, 'refresh_expired');
                throw new AuthenticationException;
            }

            $this->revoke($session, 'refresh_rotated');
            $tokens = $this->create(
                $session->user,
                $request,
                $session->mfa_verified_at !== null,
                $session->trusted_until?->isFuture() ?? false,
            );
            $tokens['session']->forceFill([
                'refresh_family_id' => $session->refresh_family_id,
                'refresh_sequence' => $session->refresh_sequence + 1,
            ])->save();

            return $tokens;
        });
    }

    public function revoke(UserSession $session, string $reason): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill([
                'revoked_at' => now(),
                'revocation_reason' => $reason,
                'record_version' => $session->record_version + 1,
            ])->save();
        }
    }

    public function revokeAll(User $user, string $reason, ?string $exceptId = null): int
    {
        return UserSession::query()->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['revoked_at' => now(), 'revocation_reason' => $reason]);
    }

    /** @return array{string,string} */
    private function token(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return [$token, hash('sha256', $token)];
    }
}
