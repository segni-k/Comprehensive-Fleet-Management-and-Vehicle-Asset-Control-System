<?php

namespace App\Outbox\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class OutboxModel extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
