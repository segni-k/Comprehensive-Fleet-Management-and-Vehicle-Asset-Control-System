<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserSession extends IdentityModel
{
    protected $fillable = [
        'user_id',
        'access_token_hash',
        'refresh_token_hash',
        'refresh_family_id',
        'refresh_sequence',
        'auth_strength',
        'mfa_verified_at',
        'trusted_until',
        'access_expires_at',
        'refresh_expires_at',
        'last_seen_at',
        'revoked_at',
        'revocation_reason',
        'ip_hash',
        'user_agent_hash',
    ];

    protected $hidden = [
        'access_token_hash',
        'refresh_token_hash',
        'ip_hash',
        'user_agent_hash',
    ];

    protected $attributes = [
        'refresh_sequence' => 1,
        'auth_strength' => 'password',
        'record_version' => 1,
    ];

    protected $casts = [
        'mfa_verified_at' => 'immutable_datetime',
        'trusted_until' => 'immutable_datetime',
        'access_expires_at' => 'immutable_datetime',
        'refresh_expires_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
