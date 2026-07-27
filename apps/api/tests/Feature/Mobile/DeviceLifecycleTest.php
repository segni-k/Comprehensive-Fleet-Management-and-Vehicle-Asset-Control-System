<?php

namespace Tests\Feature\Mobile;

use App\Fleet\Models\Driver;
use App\Identity\Models\User;
use App\Mobile\Models\MobileDevice;
use App\Organization\Models\Organization;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $manager;
    private MobileDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityPermissionSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->manager      = User::factory()->create();

        foreach (['mobile.device.suspend', 'mobile.device.activate', 'mobile.device.revoke', 'mobile.device.retire', 'mobile.device.view'] as $perm) {
            $this->grantPermission($this->manager, $perm, $this->organization->id);
        }

        $driver = Driver::factory()->create(['organization_id' => $this->organization->id]);

        $this->device = MobileDevice::factory()->create([
            'organization_id'  => $this->organization->id,
            'driver_id'        => $driver->id,
            'lifecycle_state'  => 'active',
            'enrollment_state' => 'enrolled',
            'trust_state'      => 'trusted',
        ]);
    }

    public function test_manager_can_suspend_active_device(): void
    {
        $this->actingAs($this->manager)
            ->postJson("/api/v1/mobile/devices/{$this->device->id}/suspend", [
                'reason' => 'Suspicious activity detected',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.lifecycle_state', 'suspended');

        $this->assertDatabaseHas('device_status_history', [
            'mobile_device_id' => $this->device->id,
            'status_type'      => 'lifecycle',
            'from_state'       => 'active',
            'to_state'         => 'suspended',
        ]);
    }

    public function test_manager_cannot_suspend_already_suspended_device(): void
    {
        $this->device->update(['lifecycle_state' => 'suspended']);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/mobile/devices/{$this->device->id}/suspend", [
                'reason' => 'Already suspended',
            ])
            ->assertStatus(422);
    }

    public function test_revoked_device_creates_remote_sign_out_action(): void
    {
        $this->actingAs($this->manager)
            ->postJson("/api/v1/mobile/devices/{$this->device->id}/revoke", [
                'reason' => 'Device reported lost',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.lifecycle_state', 'revoked');

        $this->assertDatabaseHas('device_remote_actions', [
            'mobile_device_id' => $this->device->id,
            'action_type'      => 'sign_out',
            'status'           => 'pending',
        ]);
    }

    public function test_cannot_revoke_already_revoked_device(): void
    {
        $this->device->update(['lifecycle_state' => 'revoked']);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/mobile/devices/{$this->device->id}/revoke", [
                'reason' => 'Duplicate revoke',
            ])
            ->assertStatus(422);
    }

    public function test_manager_can_reactivate_suspended_device(): void
    {
        $this->device->update(['lifecycle_state' => 'suspended']);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/mobile/devices/{$this->device->id}/reactivate", [
                'reason' => 'Investigation complete — device cleared',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.lifecycle_state', 'active');
    }

    public function test_device_list_respects_organization_scope(): void
    {
        $otherOrg    = Organization::factory()->create();
        $otherDevice = MobileDevice::factory()->create([
            'organization_id' => $otherOrg->id,
            'lifecycle_state' => 'active',
        ]);

        $response = $this->actingAs($this->manager)
            ->getJson("/api/v1/mobile/devices?organization_id={$this->organization->id}");

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($this->device->id, $ids->toArray());
        $this->assertNotContains($otherDevice->id, $ids->toArray());
    }

    private function grantPermission(User $user, string $permCode, string $orgId): void
    {
        $permission = \App\Identity\Models\Permission::query()->where('code', $permCode)->first();
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
