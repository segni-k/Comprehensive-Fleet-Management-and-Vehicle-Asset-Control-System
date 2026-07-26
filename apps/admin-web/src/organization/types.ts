export interface OrganizationType {
  readonly id: string;
  readonly code: string;
  readonly name_key: string;
  readonly description: string;
  readonly status: string;
  readonly configuration_status: string;
  readonly sort_order: number;
  readonly may_be_root: boolean;
  readonly record_version: number;
}

export interface OrganizationSummary {
  readonly id: string;
  readonly type_id: string;
  readonly code: string;
  readonly name: Readonly<Record<"en" | "om" | "am", string>>;
  readonly status: string;
  readonly description?: string | null;
  readonly effective_from?: string;
  readonly effective_to?: string | null;
  readonly record_version: number;
}

export interface OrganizationTreeNode extends OrganizationSummary {
  readonly children: readonly OrganizationTreeNode[];
}

export interface HierarchyMovePreview {
  readonly id: string;
  readonly current_parent_id: string | null;
  readonly proposed_parent_id: string;
  readonly affected_descendants: readonly string[];
  readonly affected_manager_assignments: number;
  readonly affected_role_assignments: readonly string[];
  readonly affected_records_by_category: Readonly<Record<string, number>>;
  readonly warnings: readonly string[];
  readonly blockers: readonly string[];
  readonly workflow_impact: Readonly<Record<string, unknown>>;
  readonly configuration_impact: Readonly<Record<string, unknown>>;
  readonly preview_version: number;
  readonly requested_effective_at: string;
  readonly expires_at: string;
}

export interface ApiEnvelope<T> {
  readonly data: T;
  readonly meta: { readonly correlation_id: string };
}

export interface OrganizationTypeRule {
  readonly id: string;
  readonly parent_type_id: string;
  readonly child_type_id: string;
  readonly status: string;
  readonly effective_from: string;
  readonly effective_to: string | null;
  readonly record_version: number;
}

export interface OrganizationContact {
  readonly id: string;
  readonly contact_type: string;
  readonly value: string;
  readonly is_primary: boolean;
  readonly status: string;
  readonly effective_from: string;
  readonly effective_to: string | null;
  readonly record_version: number;
}

export interface OrganizationManager {
  readonly id: string;
  readonly user_id: string;
  readonly responsibility_id: string;
  readonly appointing_authority: string;
  readonly approval_reference: string | null;
  readonly status: string;
  readonly effective_from: string;
  readonly effective_to: string | null;
  readonly record_version: number;
}

export interface EffectiveSetting {
  readonly setting_definition_id: string;
  readonly value: unknown;
  readonly source_organization_id: string;
  readonly source_setting_version: number;
  readonly effective_from: string;
  readonly override_status: "local" | "inherited";
}

export interface OrganizationSetting {
  readonly id: string;
  readonly setting_definition_id: string;
  readonly value: unknown;
  readonly effective_from: string;
  readonly effective_to: string | null;
  readonly record_version: number;
}

export interface AuditEvent {
  readonly id: string;
  readonly event_type: string;
  readonly subject_type: string;
  readonly subject_id: string;
  readonly actor_reference: string;
  readonly reason: string;
  readonly occurred_at: string;
}

export interface HierarchyMove {
  readonly id: string;
  readonly source_organization_id: string;
  readonly proposed_parent_id: string;
  readonly preview_id: string;
  readonly preview_version: number;
  readonly approval_status: string;
  readonly application_status: string;
  readonly requested_effective_at: string;
  readonly scheduled_at: string | null;
  readonly reason: string;
  readonly record_version: number;
}
