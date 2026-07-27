<?php

namespace App\Workflow\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WorkflowInstance extends WorkflowModel
{
    protected $fillable = [
        'workflow_definition_id', 'current_state_id', 'organization_id', 'subject_type',
        'subject_id', 'created_by', 'context_snapshot', 'due_at', 'status', 'record_version',
    ];

    protected $attributes = ['status' => 'active', 'record_version' => 1];

    protected $casts = ['context_snapshot' => 'array', 'due_at' => 'immutable_datetime'];

    /** @return BelongsTo<WorkflowDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    /** @return BelongsTo<WorkflowState, $this> */
    public function currentState(): BelongsTo
    {
        return $this->belongsTo(WorkflowState::class, 'current_state_id');
    }

    /** @return HasMany<WorkflowAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class);
    }

    /** @return HasMany<WorkflowAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkflowAssignment::class);
    }

    /** @return HasMany<WorkflowComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(WorkflowComment::class);
    }
}
