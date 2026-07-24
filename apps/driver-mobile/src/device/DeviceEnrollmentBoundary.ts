export interface EnrollmentRequest {
  readonly oneTimeCode: string;
  readonly publicKey: string;
  readonly appVersion: string;
  readonly platform: "android";
}

export interface DeviceEnrollmentBoundary {
  activate(request: EnrollmentRequest): Promise<{ readonly deviceId: string }>;
  revokeLocalAccess(): Promise<void>;
}
