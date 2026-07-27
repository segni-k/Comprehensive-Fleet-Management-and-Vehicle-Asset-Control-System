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

export interface LocalizedName {
  readonly en: string;
  readonly om: string;
  readonly am: string;
}

export interface GeographyDashboard {
  readonly places: {
    readonly total: number;
    readonly active: number;
    readonly without_coordinates: number;
    readonly inactive: number;
  };
  readonly routes: {
    readonly total: number;
    readonly active: number;
    readonly draft_versions: number;
  };
  readonly distance_references: {
    readonly approved: number;
    readonly draft: number;
    readonly legs: number;
  };
  readonly operational_zones: number;
  readonly generated_at: string;
}

export interface PlaceCategory {
  readonly id: string;
  readonly code: string;
  readonly name: LocalizedName;
  readonly classification: string;
  readonly requires_coordinates: boolean;
}

export interface PlaceSummary {
  readonly id: string;
  readonly code: string;
  readonly name: LocalizedName;
  readonly category?: PlaceCategory;
  readonly latitude: string | null;
  readonly longitude: string | null;
  readonly status: string;
  readonly record_version: number;
}

export interface RouteVersion {
  readonly id: string;
  readonly version: number;
  readonly alternative_label: string;
  readonly preferred: boolean;
  readonly estimated_distance_km: string;
  readonly estimated_duration_minutes: number;
  readonly status: string;
  readonly record_version: number;
}

export interface RouteSummary {
  readonly id: string;
  readonly code: string;
  readonly name: LocalizedName;
  readonly origin_place_id: string;
  readonly destination_place_id: string;
  readonly directional: boolean;
  readonly status: string;
  readonly versions: readonly RouteVersion[];
}

export interface DistanceReferenceSummary {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly source_type: string;
  readonly status: string;
  readonly record_version: number;
  readonly legs_count: number;
}

export interface OperationalZoneSummary {
  readonly id: string;
  readonly code: string;
  readonly name: LocalizedName;
  readonly zone_type: string;
  readonly status: string;
}

export interface GeographyImportSummary {
  readonly id: string;
  readonly import_type: "places" | "routes" | "distance_matrix";
  readonly source_name: string;
  readonly source_checksum: string;
  readonly row_count: number;
  readonly valid_row_count: number;
  readonly invalid_row_count: number;
  readonly status:
    | "validated"
    | "validation_failed"
    | "approved_applied_draft"
    | "rolled_back";
  readonly imported_by: string;
  readonly approved_by: string | null;
  readonly created_at: string;
}
