<?php

namespace App\Audit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class AuditModel extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
