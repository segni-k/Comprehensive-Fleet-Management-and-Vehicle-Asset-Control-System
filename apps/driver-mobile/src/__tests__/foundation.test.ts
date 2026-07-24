import { determineVersionState } from "../device/ApplicationVersionPolicy";
import { EncryptedPayloadCodec } from "../offline/EncryptedPayloadCodec";
import { InMemoryOfflineCommandQueue } from "../offline/OfflineCommandQueue";
import { isNetworkOnline } from "../network/network-state";
import type { CredentialStore } from "../security/CredentialStore";

class MemoryCredentialStore implements CredentialStore {
  private readonly values = new Map<string, string>();

  async get(key: string): Promise<string | null> {
    return this.values.get(key) ?? null;
  }

  async set(key: string, value: string): Promise<void> {
    this.values.set(key, value);
  }

  async remove(key: string): Promise<void> {
    this.values.delete(key);
  }
}

describe("mobile platform foundation", () => {
  it("enforces forced updates and revoked devices", () => {
    expect(
      determineVersionState({
        currentVersion: "1.0.0",
        minimumVersion: "1.1.0",
        deviceRevoked: false,
      }),
    ).toBe("forced_update");
    expect(
      determineVersionState({
        currentVersion: "2.0.0",
        minimumVersion: "1.1.0",
        deviceRevoked: true,
      }),
    ).toBe("revoked_device");
  });

  it("orders pending offline commands and rejects duplicate identifiers", async () => {
    const queue = new InMemoryOfflineCommandQueue();
    const base = {
      idempotencyKey: "idempotency-key-0001",
      type: "foundation.test",
      capturedAt: "2026-07-24T10:00:00Z",
      payload: {},
      status: "local_pending" as const,
    };
    await queue.enqueue({ ...base, clientCommandId: "b", sequence: 2 });
    await queue.enqueue({
      ...base,
      idempotencyKey: "idempotency-key-0002",
      clientCommandId: "a",
      sequence: 1,
    });
    await expect(
      queue.enqueue({ ...base, clientCommandId: "a", sequence: 3 }),
    ).rejects.toThrow("DUPLICATE_CLIENT_COMMAND");
    expect((await queue.pending()).map((command) => command.sequence)).toEqual([
      1, 2,
    ]);
  });

  it("treats unavailable or disconnected networks as offline", () => {
    expect(
      isNetworkOnline({ isConnected: true, isInternetReachable: true }),
    ).toBe(true);
    expect(
      isNetworkOnline({ isConnected: false, isInternetReachable: false }),
    ).toBe(false);
    expect(
      isNetworkOnline({ isConnected: true, isInternetReachable: false }),
    ).toBe(false);
  });

  it("encrypts offline payloads through the credential-store boundary", async () => {
    const codec = new EncryptedPayloadCodec(new MemoryCredentialStore());
    const encrypted = await codec.encrypt({ secret: "not-plaintext" });
    expect(encrypted).not.toContain("not-plaintext");
    await expect(codec.decrypt(encrypted)).resolves.toEqual({
      secret: "not-plaintext",
    });
  });
});
