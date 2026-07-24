import { gcm } from "@noble/ciphers/aes";
import { bytesToUtf8, utf8ToBytes } from "@noble/ciphers/utils";
import { getRandomBytes } from "expo-crypto";
import { fromByteArray, toByteArray } from "base64-js";
import type { CredentialStore } from "../security/CredentialStore";

export class EncryptedPayloadCodec {
  private static readonly KEY_NAME = "offline-queue-encryption-key-v1";

  constructor(private readonly credentials: CredentialStore) {}

  async encrypt(payload: Readonly<Record<string, unknown>>): Promise<string> {
    const key = await this.key();
    const nonce = getRandomBytes(12);
    const plaintext = utf8ToBytes(JSON.stringify(payload));
    const ciphertext = gcm(key, nonce).encrypt(plaintext);
    return `${fromByteArray(nonce)}.${fromByteArray(ciphertext)}`;
  }

  async decrypt(value: string): Promise<Readonly<Record<string, unknown>>> {
    const [nonceValue, ciphertextValue] = value.split(".");
    if (!nonceValue || !ciphertextValue)
      throw new Error("INVALID_ENCRYPTED_PAYLOAD");
    const plaintext = gcm(await this.key(), toByteArray(nonceValue)).decrypt(
      toByteArray(ciphertextValue),
    );
    return JSON.parse(bytesToUtf8(plaintext)) as Readonly<
      Record<string, unknown>
    >;
  }

  private async key(): Promise<Uint8Array> {
    const existing = await this.credentials.get(EncryptedPayloadCodec.KEY_NAME);
    if (existing) return toByteArray(existing);
    const created = getRandomBytes(32);
    await this.credentials.set(
      EncryptedPayloadCodec.KEY_NAME,
      fromByteArray(created),
    );
    return created;
  }
}
