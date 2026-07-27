import { fetch, type FetchRequestInit } from "expo/fetch";
import { randomUUID } from "expo-crypto";
import { environment } from "../config/environment";
import type { CredentialStore } from "../security/CredentialStore";
import type {
  DriverAssignmentDataSource,
  DriverAssignmentSnapshot,
  DriverVehicleAssignment,
} from "./types";

interface CollectionEnvelope {
  readonly data: readonly DriverVehicleAssignment[];
}

interface ResourceEnvelope {
  readonly data: DriverVehicleAssignment;
}

export class DriverAssignmentApiClient implements DriverAssignmentDataSource {
  private static readonly ACCESS_TOKEN_KEY = "identity-access-token-v1";

  constructor(private readonly credentials: CredentialStore) {}

  async list(signal?: AbortSignal): Promise<DriverAssignmentSnapshot> {
    const result = await this.request<CollectionEnvelope>(
      "/me/vehicle-assignments",
      signal ? { signal } : {},
    );
    return {
      assignments: result.data,
      synchronizedAt: new Date().toISOString(),
    };
  }

  async acknowledge(
    assignmentId: string,
    signal?: AbortSignal,
  ): Promise<DriverVehicleAssignment> {
    const result = await this.request<ResourceEnvelope>(
      `/me/vehicle-assignments/${assignmentId}/acknowledge`,
      {
        method: "POST",
        headers: { "Idempotency-Key": randomUUID() },
        ...(signal ? { signal } : {}),
      },
    );
    return result.data;
  }

  private async request<T>(path: string, init: FetchRequestInit): Promise<T> {
    const token = await this.credentials.get(
      DriverAssignmentApiClient.ACCESS_TOKEN_KEY,
    );
    if (!token) throw new Error("AUTHENTICATION_REQUIRED");
    const headers = new Headers(init.headers);
    headers.set("Accept", "application/json");
    headers.set("Authorization", `Bearer ${token}`);
    const response = await fetch(`${environment.apiBaseUrl}${path}`, {
      ...init,
      headers,
    });
    if (!response.ok) {
      throw new Error(
        response.status === 403 ? "AUTHORIZATION_DENIED" : "REQUEST_FAILED",
      );
    }
    return (await response.json()) as T;
  }
}
