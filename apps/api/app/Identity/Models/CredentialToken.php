<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CredentialToken extends IdentityModel
{
    protected $table = 'user_credential_tokens';

    protected $fillable = [
        'user_id', 'purpose', 'token_hash', 'expires_at', 'used_at', 'requested_ip_hash',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'used_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
