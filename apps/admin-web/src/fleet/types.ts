export interface ApiEnvelope<T> {
  readonly data: T;
}

export interface PagedEnvelope<T> {
  readonly data: readonly T[];
  readonly meta?: {
    readonly current_page: number;
    readonly last_page: number;
    readonly total: number;
  };
}

export interface FleetDashboard {
  readonly vehicles: {
    readonly total: number;
    readonly active: number;
    readonly unavailable: number;
    readonly retired: number;
    readonly unassigned: number;
  };
  readonly drivers: {
    readonly total: number;
    readonly available: number;
    readonly assigned: number;
    readonly licences_expiring_30_days: number;
  };
  readonly compliance: {
    readonly expiring_30_days: number;
    readonly expired: number;
  };
  readonly assignments: {
    readonly active: number;
    readonly awaiting_acknowledgement: number;
  };
  readonly generated_at: string;
}

export interface VehicleSummary {
  readonly id: string;
  readonly asset_number: string;
  readonly plate_number: string | null;
  readonly manufacturer?: { readonly name: string };
  readonly model?: { readonly name: string };
  readonly model_year: number | null;
  readonly current_odometer_km: string;
  readonly ownership_type: string;
  readonly status: string;
  readonly record_version: number;
}

export interface DriverSummary {
  readonly id: string;
  readonly employee_number: string;
  readonly full_name: string;
  readonly status: string;
  readonly availability_status: string;
  readonly licences?: readonly {
    readonly id: string;
    readonly issuing_authority: string;
    readonly issued_on: string | null;
    readonly expires_on: string;
    readonly status: string;
    readonly classes: readonly {
      readonly id: string;
      readonly code: string;
    }[];
  }[];
}

export interface AssignmentSummary {
  readonly id: string;
  readonly assignment_type: string;
  readonly starts_at: string;
  readonly ends_at: string | null;
  readonly status: string;
  readonly acknowledgement_required: boolean;
  readonly acknowledged_at: string | null;
  readonly vehicle?: {
    readonly id: string;
    readonly asset_number: string;
    readonly plate_number: string | null;
  };
  readonly driver?: {
    readonly id: string;
    readonly employee_number: string;
    readonly full_name: string;
  };
}

export interface FleetReferenceData {
  readonly categories: readonly ReferenceRecord[];
  readonly classes: readonly ReferenceRecord[];
  readonly manufacturers: readonly ReferenceRecord[];
  readonly models: readonly ReferenceRecord[];
  readonly trims: readonly ReferenceRecord[];
  readonly licence_classes: readonly ReferenceRecord[];
  readonly fleet_units: readonly ReferenceRecord[];
}

export interface ReferenceRecord {
  readonly id: string;
  readonly code: string;
  readonly name: string | Record<"en" | "om" | "am", string>;
  readonly manufacturer_id?: string;
  readonly vehicle_category_id?: string;
}

export interface FleetDocumentSummary {
  readonly id: string;
  readonly document_type: string;
  readonly category: string;
  readonly classification: string;
  readonly status: string;
  readonly expires_at: string | null;
  readonly record_version: number;
  readonly original_filename: string | null;
  readonly media_type: string | null;
  readonly size_bytes: number | null;
  readonly scan_status: string | null;
  readonly trust_status: string | null;
  readonly created_at: string;
}
