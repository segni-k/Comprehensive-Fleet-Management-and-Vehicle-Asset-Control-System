import * as SQLite from "expo-sqlite";
import type { EncryptedPayloadCodec } from "../offline/EncryptedPayloadCodec";
import type {
  DriverAssignmentCache,
  DriverAssignmentSnapshot,
  DriverVehicleAssignment,
} from "./types";

interface CacheRow {
  readonly encrypted_payload: string;
}

export class SqliteDriverAssignmentCache implements DriverAssignmentCache {
  private database?: SQLite.SQLiteDatabase;

  constructor(private readonly codec: EncryptedPayloadCodec) {}

  async initialize(): Promise<void> {
    this.database = await SQLite.openDatabaseAsync(
      "driver-assignment-cache.db",
    );
    await this.database.execAsync(`
      PRAGMA journal_mode = WAL;
      CREATE TABLE IF NOT EXISTS assignment_snapshot (
        cache_key TEXT PRIMARY KEY NOT NULL,
        encrypted_payload TEXT NOT NULL,
        synchronized_at TEXT NOT NULL
      );
    `);
  }

  async load(): Promise<DriverAssignmentSnapshot | null> {
    const row = await this.requireDatabase().getFirstAsync<CacheRow>(
      "SELECT encrypted_payload FROM assignment_snapshot WHERE cache_key = ?",
      "own-assignments",
    );
    if (!row) return null;
    const payload = await this.codec.decrypt(row.encrypted_payload);
    if (
      !Array.isArray(payload.assignments) ||
      typeof payload.synchronizedAt !== "string"
    ) {
      throw new Error("INVALID_ASSIGNMENT_CACHE");
    }
    return {
      assignments: payload.assignments as unknown as DriverVehicleAssignment[],
      synchronizedAt: payload.synchronizedAt,
    };
  }

  async save(snapshot: DriverAssignmentSnapshot): Promise<void> {
    const encrypted = await this.codec.encrypt({
      assignments: [...snapshot.assignments],
      synchronizedAt: snapshot.synchronizedAt,
    });
    await this.requireDatabase().runAsync(
      `INSERT INTO assignment_snapshot (cache_key, encrypted_payload, synchronized_at)
       VALUES (?, ?, ?)
       ON CONFLICT(cache_key) DO UPDATE SET
       encrypted_payload = excluded.encrypted_payload,
       synchronized_at = excluded.synchronized_at`,
      "own-assignments",
      encrypted,
      snapshot.synchronizedAt,
    );
  }

  private requireDatabase(): SQLite.SQLiteDatabase {
    if (!this.database) throw new Error("ASSIGNMENT_CACHE_NOT_INITIALIZED");
    return this.database;
  }
}
