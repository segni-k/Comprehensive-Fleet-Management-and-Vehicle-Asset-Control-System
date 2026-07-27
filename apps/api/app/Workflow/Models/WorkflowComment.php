<?php

namespace App\Workflow\Models;

final class WorkflowComment extends WorkflowModel
{
    public $timestamps = false;

    protected $fillable = [
        'workflow_instance_id', 'author_user_id', 'body', 'document_id', 'created_at',
    ];

    protected $casts = ['created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new \LogicException('Workflow comments are append-only.'));
        self::deleting(fn (): never => throw new \LogicException('Workflow comments are append-only.'));
    }
}
