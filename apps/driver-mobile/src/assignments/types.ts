export interface DriverVehicleAssignment {
  readonly id: string;
  readonly assignment_type: string;
  readonly starts_at: string;
  readonly ends_at: string | null;
  readonly acknowledgement_required: boolean;
  readonly acknowledged_at: string | null;
  readonly status: string;
  readonly record_version: number;
  readonly vehicle?: {
    readonly id: string;
    readonly asset_number: string;
    readonly plate_number: string | null;
    readonly status: string;
    readonly compliance: readonly {
      readonly document_type: string;
      readonly expires_on: string | null;
      readonly status: "current" | "expired";
    }[];
  };
}

export interface DriverAssignmentSnapshot {
  readonly assignments: readonly DriverVehicleAssignment[];
  readonly synchronizedAt: string;
}

export interface DriverAssignmentDataSource {
  list(signal?: AbortSignal): Promise<DriverAssignmentSnapshot>;
  acknowledge(
    assignmentId: string,
    signal?: AbortSignal,
  ): Promise<DriverVehicleAssignment>;
}

export interface DriverAssignmentCache {
  initialize(): Promise<void>;
  load(): Promise<DriverAssignmentSnapshot | null>;
  save(snapshot: DriverAssignmentSnapshot): Promise<void>;
}
