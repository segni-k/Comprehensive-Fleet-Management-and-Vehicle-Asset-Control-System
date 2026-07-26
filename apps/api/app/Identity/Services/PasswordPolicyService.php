<?php

namespace App\Identity\Services;

use App\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class PasswordPolicyService
{
    public function assertAcceptable(string $password, ?User $user = null): void
    {
        $minimum = (int) config('identity.password.minimum_length');
        $invalid = mb_strlen($password) < $minimum
            || ! preg_match('/[a-z]/', $password)
            || ! preg_match('/[A-Z]/', $password)
            || ! preg_match('/[0-9]/', $password)
            || ! preg_match('/[^A-Za-z0-9]/', $password);

        if ($user !== null) {
            $identifier = mb_strtolower($user->login_identifier);
            $invalid = $invalid || ($identifier !== '' && str_contains(mb_strtolower($password), $identifier));
        }

        if ($invalid) {
            throw ValidationException::withMessages([
                'password' => ['Password does not meet the configured complexity policy.'],
            ]);
        }

        if ($user?->password && Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['Password was used recently.']]);
        }

        $history = $user?->passwordHistory()
            ->latest('created_at')
            ->limit((int) config('identity.password.history_count'))
            ->pluck('password_hash') ?? collect();

        if ($history->contains(fn (string $hash): bool => Hash::check($password, $hash))) {
            throw ValidationException::withMessages(['password' => ['Password was used recently.']]);
        }
    }
}
