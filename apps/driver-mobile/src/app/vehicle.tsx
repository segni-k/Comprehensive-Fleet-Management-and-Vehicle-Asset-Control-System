import { useMemo } from "react";
import { DriverAssignmentApiClient } from "../assignments/DriverAssignmentApiClient";
import { SqliteDriverAssignmentCache } from "../assignments/SqliteDriverAssignmentCache";
import { DriverVehicleWorkspace } from "../components/DriverVehicleWorkspace";
import { EncryptedPayloadCodec } from "../offline/EncryptedPayloadCodec";
import { ExpoSecureCredentialStore } from "../security/CredentialStore";

export default function VehicleScreen() {
  const dependencies = useMemo(() => {
    const credentials = new ExpoSecureCredentialStore();
    return {
      dataSource: new DriverAssignmentApiClient(credentials),
      cache: new SqliteDriverAssignmentCache(
        new EncryptedPayloadCodec(credentials),
      ),
    };
  }, []);

  return <DriverVehicleWorkspace {...dependencies} />;
}
