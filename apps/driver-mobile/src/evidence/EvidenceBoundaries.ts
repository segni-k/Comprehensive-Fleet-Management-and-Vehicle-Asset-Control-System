export interface ForegroundLocationEvidence {
  readonly latitude: number;
  readonly longitude: number;
  readonly accuracyMeters: number | null;
  readonly capturedAt: string;
}

export interface ForegroundLocationBoundary {
  requestForExplicitAction(): Promise<ForegroundLocationEvidence>;
}

export interface CapturedFile {
  readonly uri: string;
  readonly mediaType: string;
  readonly sizeBytes?: number;
}

export interface EvidenceCaptureBoundary {
  capturePhoto(): Promise<CapturedFile>;
  selectDocument(): Promise<CapturedFile>;
}
