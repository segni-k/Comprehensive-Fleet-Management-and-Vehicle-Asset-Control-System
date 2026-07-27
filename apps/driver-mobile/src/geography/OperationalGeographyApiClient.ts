import { fetch } from "expo/fetch";
import { environment } from "../config/environment";
import type { CredentialStore } from "../security/CredentialStore";
import type {
  MobileDistanceLeg,
  MobilePlace,
  MobileRoute,
  OperationalGeographyDataSource,
  OperationalGeographySnapshot,
} from "./types";

interface GeographyEnvelope {
  readonly data: {
    readonly places: readonly MobilePlace[];
    readonly routes: readonly MobileRoute[];
    readonly distance_legs: readonly MobileDistanceLeg[];
    readonly synchronized_at: string;
  };
}

export class OperationalGeographyApiClient
  implements OperationalGeographyDataSource
{
  private static readonly ACCESS_TOKEN_KEY = "identity-access-token-v1";

  constructor(private readonly credentials: CredentialStore) {}

  async load(signal?: AbortSignal): Promise<OperationalGeographySnapshot> {
    const token = await this.credentials.get(
      OperationalGeographyApiClient.ACCESS_TOKEN_KEY,
    );
    if (!token) throw new Error("AUTHENTICATION_REQUIRED");
    const response = await fetch(
      `${environment.apiBaseUrl}/me/operational-geography`,
      {
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        ...(signal ? { signal } : {}),
      },
    );
    if (!response.ok) {
      throw new Error(
        response.status === 403 ? "AUTHORIZATION_DENIED" : "REQUEST_FAILED",
      );
    }
    const result = (await response.json()) as GeographyEnvelope;
    return {
      places: result.data.places,
      routes: result.data.routes,
      distanceLegs: result.data.distance_legs,
      synchronizedAt: result.data.synchronized_at,
    };
  }
}
