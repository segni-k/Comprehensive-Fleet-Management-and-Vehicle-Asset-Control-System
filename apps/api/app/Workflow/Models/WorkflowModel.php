<?php

namespace App\Workflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class WorkflowModel extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';
}
