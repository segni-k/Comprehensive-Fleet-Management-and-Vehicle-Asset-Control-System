<?php

namespace Tests\Feature\Mobile;

use App\Fleet\Models\Driver;
use App\Identity\Models\User;
use App\Mobile\Models\DeviceEnrollmentChallenge;
use App\Mobile\Models\DeviceEnrollmentRequest;
use App\Mobile\Models\MobileDevice;
use App\Organization\Models\Organization;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $checker;
    private Organization $organization;
    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityPermissionSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->admin        = User::factory()->create();
        $this->checker      = User::factory()->create();
        $this->driver       = Driver::factory()->create(['organization_id' => $this->organization->id]);

        // Grant permissions
        $this->grantPermission($this->admin, 'mobile.device.enroll', $this->organization->id);
        $this->grantPermission($this->checker, 'mobile.device.approve', $this->organization->id);
        $this->grantPermission($this->checker, 'mobile.device.reject', $this->organization->id);
        $this->grantPermission($this->checker, 'mobile.device.view', $this->organization->id);
    }

    public function test_admin_can_initiate_enrollment(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/mobile/enrollments', [
                'organization_id' => $this->organization->id,
                'driver_id'       => $this->driver->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.driver_id', $this->driver->id)
            ->assertJsonStructure(['data' => ['challenge_id', 'enrollment_code', 'expires_at', 'driver_id']]);

        $this->assertDatabaseHas('device_enrollment_challenges', [
            'organization_id' => $this->organization->id,
            'driver_id'       => $this->driver->id,
            'status'          => 'active',
        ]);
    }

    public function test_enrollment_code_is_not_stored_in_plaintext(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/mobile/enrollments', [
                'organization_id' => $this->organization->id,
                'driver_id'       => $this->driver->id,
            ]);

        $code = $response->json('data.enrollment_code');
        $hash = hash('sha256', $code);

        // DB stores the hash, not the plaintext
        $this->assertDatabaseHas('device_enrollment_challenges', ['challenge_hash' => $hash]);
        $this->assertDatabaseMissing('device_enrollment_challenges', ['challenge_hash' => $code]);
    }

    public function test_driver_can_claim_challenge(): void
    {
        // Step 1: Admin creates challenge
        $initResponse = $this->actingAs($this->admin)
            ->postJson('/api/v1/mobile/enrollments', [
                'organization_id' => $this->organization->id,
                'driver_id'       => $this->driver->id,
            ]);

        $code = $initResponse->json('data.enrollment_code');

        // Step 2: Driver claims challenge
        $claimResponse = $this->postJson('/api/v1/driver/device/claim', [
            'enrollment_code'  => $code,
            'stable_device_id' => 'TEST_STABLE_DEVICE_001',
            'installation_id'  => (string) Str::uuid(),
            'display_name'     => 'Test Android Device',
            'os_version'       => '13.0',
            'app_version'      => '1.0.0',
        ]);

        $claimResponse->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('mobile_devices', [
            'stable_device_id' => 'TEST_STABLE_DEVICE_001',
            'enrollment_state' => 'pending_approval',
            'lifecycle_state'  => 'pending',
        ]);

        // Challenge should now be 'claimed' (single-use)
        $this->assertDatabaseHas('device_enrollment_challenges', [
            'status' => 'claimed',
        ]);
    }

    public function test_challenge_is_single_use(): void
    {
        $initResponse = $this->actingAs($this->admin)
            ->postJson('/api/v1/mobile/enrollments', [
                'organization_id' => $this->organization->id,
                'driver_id'       => $this->driver->id,
            ]);

        $code = $initResponse->json('data.enrollment_code');

        // First claim
        $this->postJson('/api/v1/driver/device/claim', [
            'enrollment_code'  => $code,
            'stable_device_id' => 'DEVICE_1',
            'installation_id'  => (string) Str::uuid(),
        ])->assertStatus(201);

        // Second claim with same code must fail
        $this->postJson('/api/v1/driver/device/claim', [
            'enrollment_code'  => $code,
            'stable_device_id' => 'DEVICE_2',
            'installation_id'  => (string) Str::uuid(),
        ])->assertStatus(422);
    }

    public function test_checker_can_approve_enrollment_and_maker_checker_is_enforced(): void
    {
        // Same user initiates and then tries to approve — must fail
        $initResponse = $this->actingAs($this->admin)
            ->postJson('/api/v1/mobile/enrollments', [
                'organization_id' => $this->organization->id,
                'driver_id'       => $this->driver->id,
            ]);

        $code = $initResponse->json('data.enrollment_code');

        $this->postJson('/api/v1/driver/device/claim', [
            'enrollment_code'  => $code,
            'stable_device_id' => 'DEVICE_APPROVE_TEST',
            'installation_id'  => (string) Str::uuid(),
            'os_version'       => '13.0',
            'app_version'      => '1.0.0',
        ]);

        $request = DeviceEnrollmentRequest::query()->where('status', 'pending')->first();
        $this->assertNotNull($request);

        // Initiator tries to approve — maker-checker must block this
        $this->actingAs($this->admin)
            ->postJson("/api/v1/mobile/enrollments/{$request->id}/approve")
            ->assertStatus(422);

        // Checker approves — must succeed
        $approveResponse = $this->actingAs($this->checker)
            ->postJson("/api/v1/mobile/enrollments/{$request->id}/approve");

        $approveResponse->assertStatus(200)
            ->assertJsonPath('data.enrollment_state', 'enrolled')
            ->assertJsonPath('data.lifecycle_state', 'active');
    }

    public function test_checker_can_reject_enrollment_with_reason(): void
    {
        $this->createPendingEnrollmentRequest();

        $request = DeviceEnrollmentRequest::query()->where('status', 'pending')->first();

        $this->actingAs($this->checker)
            ->postJson("/api/v1/mobile/enrollments/{$request->id}/reject", [
                'reason' => 'Device not authorized for this driver',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('mobile_devices', [
            'enrollment_state' => 'rejected',
            'lifecycle_state'  => 'revoked',
        ]);
    }

    public function test_device_status_can_be_polled(): void
    {
        $installationId = (string) Str::uuid();

        $response = $this->getJson("/api/v1/driver/device/status?installation_id={$installationId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.enrollment_state', 'not_enrolled');
    }

    public function test_admin_can_view_pending_enrollments(): void
    {
        $this->createPendingEnrollmentRequest();

        $this->actingAs($this->checker)
            ->getJson("/api/v1/mobile/enrollments/pending?organization_id={$this->organization->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthorized_user_cannot_access_device_list(): void
    {
        $unauthorized = User::factory()->create();

        $this->actingAs($unauthorized)
            ->getJson("/api/v1/mobile/devices?organization_id={$this->organization->id}")
            ->assertStatus(403);
    }

    /** Helpers */
    private function createPendingEnrollmentRequest(): void
    {
        $initResponse = $this->actingAs($this->admin)
            ->postJson('/api/v1/mobile/enrollments', [
                'organization_id' => $this->organization->id,
                'driver_id'       => $this->driver->id,
            ]);

        $code = $initResponse->json('data.enrollment_code');

        $this->postJson('/api/v1/driver/device/claim', [
            'enrollment_code'  => $code,
            'stable_device_id' => 'DEVICE_PENDING_TEST',
            'installation_id'  => (string) Str::uuid(),
            'os_version'       => '13.0',
            'app_version'      => '1.0.0',
        ]);
    }

    private function grantPermission(User $user, string $permCode, string $orgId): void
    {
        $permission = \App\Identity\Models\Permission::query()
            ->where('code', $permCode)
            ->first();

        if ($permission === null) {
            return;
        }

        $role = \App\Identity\Models\Role::factory()->create(['status' => 'active']);
        $role->permissions()->attach($permission->id);

        \App\Identity\Models\UserRoleAssignment::factory()->create([
            'user_id'        => $user->id,
            'role_id'        => $role->id,
            'status'         => 'active',
            'effective_from' => now()->subDay(),
        ]);
    }
}
