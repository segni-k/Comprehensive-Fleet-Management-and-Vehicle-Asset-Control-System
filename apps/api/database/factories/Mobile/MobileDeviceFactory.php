<?php

namespace Database\Factories\Mobile;

use App\Mobile\Models\MobileDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MobileDevice>
 */
class MobileDeviceFactory extends Factory
{
    protected $model = MobileDevice::class;

    public function definition(): array
    {
        return [
            'id'               => (string) Str::ulid(),
            'organization_id'  => null, // must be provided
            'driver_id'        => null,
            'stable_device_id' => 'STABLE-' . strtoupper(Str::random(16)),
            'installation_id'  => (string) Str::uuid(),
            'display_name'     => $this->faker->words(2, true) . ' Device',
            'platform'         => 'android',
            'manufacturer'     => $this->faker->randomElement(['Samsung', 'Google', 'Motorola', 'Xiaomi']),
            'model'            => 'Galaxy A' . $this->faker->numberBetween(20, 54),
            'os_version'       => $this->faker->randomElement(['12.0', '13.0', '14.0']),
            'app_version'      => '1.0.0',
            'enrollment_state' => 'enrolled',
            'trust_state'      => 'trusted',
            'lifecycle_state'  => 'active',
            'first_seen_at'    => now()->subDays(7),
            'last_seen_at'     => now()->subHours(2),
            'last_sync_at'     => now()->subHours(3),
            'record_version'   => 1,
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'enrollment_state' => 'pending_approval',
            'lifecycle_state'  => 'pending',
            'trust_state'      => 'untrusted',
        ]);
    }

    public function revoked(): static
    {
        return $this->state([
            'enrollment_state' => 'enrolled',
            'lifecycle_state'  => 'revoked',
            'trust_state'      => 'revoked',
        ]);
    }

    public function suspended(): static
    {
        return $this->state([
            'lifecycle_state' => 'suspended',
        ]);
    }
}
