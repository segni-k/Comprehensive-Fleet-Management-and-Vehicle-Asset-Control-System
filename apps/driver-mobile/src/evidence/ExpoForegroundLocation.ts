import * as Location from "expo-location";
import type {
  ForegroundLocationBoundary,
  ForegroundLocationEvidence,
} from "./EvidenceBoundaries";

export class ExpoForegroundLocation implements ForegroundLocationBoundary {
  async requestForExplicitAction(): Promise<ForegroundLocationEvidence> {
    const permission = await Location.requestForegroundPermissionsAsync();
    if (!permission.granted)
      throw new Error("FOREGROUND_LOCATION_PERMISSION_DENIED");

    const location = await Location.getCurrentPositionAsync({
      accuracy: Location.Accuracy.High,
    });
    return {
      latitude: location.coords.latitude,
      longitude: location.coords.longitude,
      accuracyMeters: location.coords.accuracy,
      capturedAt: new Date(location.timestamp).toISOString(),
    };
  }
}
