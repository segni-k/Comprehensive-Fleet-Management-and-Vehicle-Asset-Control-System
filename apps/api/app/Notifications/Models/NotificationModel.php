<?php

namespace App\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class NotificationModel extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
