<?php

namespace App\Workflow\Models;

use Database\Factories\WorkflowDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WorkflowDefinition extends WorkflowModel
{
    /** @use HasFactory<WorkflowDefinitionFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'version_number', 'name', 'process_type', 'organization_id',
        'applicability_rules', 'assignment_rules', 'escalation_rules',
        'maker_checker_required', 'effective_from', 'effective_to', 'status', 'record_version',
    ];

    protected $attributes = ['maker_checker_required' => true, 'status' => 'draft', 'record_version' => 1];

    protected $casts = [
        'name' => 'array',
        'applicability_rules' => 'array',
        'assignment_rules' => 'array',
        'escalation_rules' => 'array',
        'maker_checker_required' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];

    /** @return HasMany<WorkflowState, $this> */
    public function states(): HasMany
    {
        return $this->hasMany(WorkflowState::class);
    }

    /** @return HasMany<WorkflowTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    protected static function newFactory(): WorkflowDefinitionFactory
    {
        return WorkflowDefinitionFactory::new();
    }
}
