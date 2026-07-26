<?php

namespace Database\Factories;

use App\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $email = mb_strtolower(fake()->unique()->safeEmail());

        return [
            'login_identifier' => $email,
            'email' => $email,
            'email_lookup_hash' => hash('sha256', $email),
            'name' => ['en' => fake()->name()],
            'preferred_locale' => 'en',
            'password' => Hash::make('Fleet!Secure123'),
            'password_changed_at' => now(),
            'must_change_password' => false,
            'status' => 'active',
        ];
    }
}
