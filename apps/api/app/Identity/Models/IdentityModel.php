<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class IdentityModel extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
