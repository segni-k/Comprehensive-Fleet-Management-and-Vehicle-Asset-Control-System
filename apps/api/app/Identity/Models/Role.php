<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Role extends IdentityModel
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_privileged',
        'status',
        'effective_from',
        'effective_to',
    ];

    protected $attributes = [
        'is_privileged' => false,
        'status' => 'draft',
        'record_version' => 1,
    ];

    protected $casts = [
        'name' => 'array',
        'is_privileged' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withPivot('constraints')->withTimestamps();
    }

    /** @return HasMany<UserRoleAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }
}
