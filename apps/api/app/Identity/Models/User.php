<?php

namespace App\Identity\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

final class User extends IdentityModel implements AuthenticatableContract, HasLocalePreference
{
    use Authenticatable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    protected $fillable = [
        'login_identifier',
        'employee_identifier',
        'email',
        'email_lookup_hash',
        'phone',
        'name',
        'preferred_locale',
        'password',
        'password_changed_at',
        'password_expires_at',
        'must_change_password',
        'failed_login_count',
        'locked_until',
        'last_login_at',
        'status',
        'status_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_lookup_hash',
    ];

    protected $attributes = [
        'preferred_locale' => 'en',
        'must_change_password' => true,
        'failed_login_count' => 0,
        'status' => 'invited',
        'record_version' => 1,
    ];

    protected $casts = [
        'email' => 'encrypted',
        'phone' => 'encrypted',
        'name' => 'array',
        'must_change_password' => 'boolean',
        'password_changed_at' => 'immutable_datetime',
        'password_expires_at' => 'immutable_datetime',
        'locked_until' => 'immutable_datetime',
        'last_login_at' => 'immutable_datetime',
    ];

    /** @return HasMany<UserSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /** @return HasMany<UserMfaMethod, $this> */
    public function mfaMethods(): HasMany
    {
        return $this->hasMany(UserMfaMethod::class);
    }

    /** @return HasMany<UserRoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    /** @return HasMany<CredentialToken, $this> */
    public function credentialTokens(): HasMany
    {
        return $this->hasMany(CredentialToken::class);
    }

    /** @return HasMany<PasswordHistory, $this> */
    public function passwordHistory(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    public function preferredLocale(): string
    {
        return $this->preferred_locale;
    }
}
