import { useMemo } from "react";
import { OperationalGeographyWorkspace } from "../components/OperationalGeographyWorkspace";
import { OperationalGeographyApiClient } from "../geography/OperationalGeographyApiClient";
import { SqliteOperationalGeographyCache } from "../geography/SqliteOperationalGeographyCache";
import { EncryptedPayloadCodec } from "../offline/EncryptedPayloadCodec";
import { ExpoSecureCredentialStore } from "../security/CredentialStore";

export default function GeographyScreen() {
  const dependencies = useMemo(() => {
    const credentials = new ExpoSecureCredentialStore();
    return {
      dataSource: new OperationalGeographyApiClient(credentials),
      cache: new SqliteOperationalGeographyCache(
        new EncryptedPayloadCodec(credentials),
      ),
    };
  }, []);

  return <OperationalGeographyWorkspace {...dependencies} />;
}
