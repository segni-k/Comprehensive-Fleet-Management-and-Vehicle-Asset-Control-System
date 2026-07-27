<?php

namespace App\Documents\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class DocumentModel extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
