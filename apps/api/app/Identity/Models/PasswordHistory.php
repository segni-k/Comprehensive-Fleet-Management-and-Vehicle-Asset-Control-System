<?php

namespace App\Identity\Models;

final class PasswordHistory extends IdentityModel
{
    public $timestamps = false;

    protected $table = 'user_password_history';

    protected $fillable = ['user_id', 'password_hash', 'created_at'];

    protected $hidden = ['password_hash'];

    protected $casts = ['created_at' => 'immutable_datetime'];
}
