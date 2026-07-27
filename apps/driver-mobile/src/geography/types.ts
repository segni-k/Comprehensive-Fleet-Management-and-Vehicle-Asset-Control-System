export interface MobilePlace {
  readonly id: string;
  readonly code: string;
  readonly name: Readonly<Record<"en" | "om" | "am", string>>;
  readonly place_category_id: string;
  readonly latitude: string | null;
  readonly longitude: string | null;
}

export interface MobileRouteSegment {
  readonly id: string;
  readonly sequence: number;
  readonly origin_place_id: string;
  readonly destination_place_id: string;
  readonly distance_km: string;
  readonly duration_minutes: number;
  readonly mandatory_stop: boolean;
}

export interface MobileRouteVersion {
  readonly id: string;
  readonly version: number;
  readonly alternative_label: string;
  readonly preferred: boolean;
  readonly estimated_distance_km: string;
  readonly estimated_duration_minutes: number;
  readonly segments: readonly MobileRouteSegment[];
}

export interface MobileRoute {
  readonly id: string;
  readonly code: string;
  readonly name: Readonly<Record<"en" | "om" | "am", string>>;
  readonly origin_place_id: string;
  readonly destination_place_id: string;
  readonly directional: boolean;
  readonly versions: readonly MobileRouteVersion[];
}

export interface MobileDistanceLeg {
  readonly origin_place_id: string;
  readonly destination_place_id: string;
  readonly route_label: string | null;
  readonly distance_km: string;
  readonly estimated_duration_minutes: number | null;
  readonly directional: boolean;
}

export interface OperationalGeographySnapshot {
  readonly places: readonly MobilePlace[];
  readonly routes: readonly MobileRoute[];
  readonly distanceLegs: readonly MobileDistanceLeg[];
  readonly synchronizedAt: string;
}

export interface OperationalGeographyDataSource {
  load(signal?: AbortSignal): Promise<OperationalGeographySnapshot>;
}

export interface OperationalGeographyCache {
  initialize(): Promise<void>;
  load(): Promise<OperationalGeographySnapshot | null>;
  save(snapshot: OperationalGeographySnapshot): Promise<void>;
}
