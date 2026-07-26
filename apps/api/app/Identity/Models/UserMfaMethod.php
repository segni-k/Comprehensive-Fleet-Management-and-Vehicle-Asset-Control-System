<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserMfaMethod extends IdentityModel
{
    protected $fillable = [
        'user_id',
        'method_type',
        'label',
        'secret',
        'recovery_codes',
        'verified_at',
        'last_used_at',
        'status',
    ];

    protected $hidden = ['secret', 'recovery_codes'];

    protected $attributes = [
        'status' => 'pending',
        'record_version' => 1,
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'recovery_codes' => 'encrypted:array',
        'verified_at' => 'immutable_datetime',
        'last_used_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
