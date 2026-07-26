<?php

namespace Tests\Feature\Identity;

use App\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_read_profile_refresh_and_logout(): void
    {
        $user = $this->user();

        $login = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'ADMIN@EXAMPLE.TEST',
            'password' => 'Fleet!Secure123',
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $access = $login->json('access_token');
        $refresh = $login->json('refresh_token');
        $this->withToken($access)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
            ->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
        $this->withToken($access)->getJson('/api/v1/me')->assertUnauthorized();

        $this->withToken($rotated->json('access_token'))->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->withToken($rotated->json('access_token'))->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_failed_logins_lock_account_without_disclosing_account_state(): void
    {
        $user = $this->user();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => $user->login_identifier,
                'password' => 'incorrect',
            ])->assertUnauthorized();
        }

        $this->assertNotNull($user->refresh()->locked_until);
        $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->login_identifier,
            'password' => 'Fleet!Secure123',
        ])->assertUnauthorized();
    }

    public function test_password_reset_is_single_use_and_revokes_existing_sessions(): void
    {
        $user = $this->user();
        $access = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->login_identifier,
            'password' => 'Fleet!Secure123',
        ])->json('access_token');

        $token = $this->postJson('/api/v1/auth/password/forgot', [
            'identifier' => $user->login_identifier,
        ])->assertAccepted()->json('reset_token');

        $payload = [
            'token' => $token,
            'password' => 'Changed!Secure456',
            'password_confirmation' => 'Changed!Secure456',
        ];
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertUnprocessable();
        $this->withToken($access)->getJson('/api/v1/me')->assertUnauthorized();
    }

    private function user(): User
    {
        return User::query()->create([
            'login_identifier' => 'admin@example.test',
            'email' => 'admin@example.test',
            'email_lookup_hash' => hash('sha256', 'admin@example.test'),
            'name' => ['en' => 'Test Administrator'],
            'password' => Hash::make('Fleet!Secure123'),
            'status' => 'active',
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }
}
