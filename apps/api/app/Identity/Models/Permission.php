<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Permission extends IdentityModel
{
    protected $fillable = [
        'code',
        'domain',
        'description',
        'allowed_scope_modes',
        'resource_types',
        'delegable',
        'requires_mfa',
        'requires_step_up',
        'maker_checker_required',
        'status',
    ];

    protected $attributes = [
        'delegable' => false,
        'requires_mfa' => false,
        'requires_step_up' => false,
        'maker_checker_required' => false,
        'status' => 'inactive',
    ];

    protected $casts = [
        'allowed_scope_modes' => 'array',
        'resource_types' => 'array',
        'delegable' => 'boolean',
        'requires_mfa' => 'boolean',
        'requires_step_up' => 'boolean',
        'maker_checker_required' => 'boolean',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withPivot('constraints')->withTimestamps();
    }
}
