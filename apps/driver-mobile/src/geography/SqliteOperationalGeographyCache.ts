import * as SQLite from "expo-sqlite";
import type { EncryptedPayloadCodec } from "../offline/EncryptedPayloadCodec";
import type {
  MobileDistanceLeg,
  MobilePlace,
  MobileRoute,
  OperationalGeographyCache,
  OperationalGeographySnapshot,
} from "./types";

interface CacheRow {
  readonly encrypted_payload: string;
}

export class SqliteOperationalGeographyCache
  implements OperationalGeographyCache
{
  private database?: SQLite.SQLiteDatabase;

  constructor(private readonly codec: EncryptedPayloadCodec) {}

  async initialize(): Promise<void> {
    this.database = await SQLite.openDatabaseAsync(
      "operational-geography-cache.db",
    );
    await this.database.execAsync(`
      PRAGMA journal_mode = WAL;
      CREATE TABLE IF NOT EXISTS geography_snapshot (
        cache_key TEXT PRIMARY KEY NOT NULL,
        encrypted_payload TEXT NOT NULL,
        synchronized_at TEXT NOT NULL
      );
    `);
  }

  async load(): Promise<OperationalGeographySnapshot | null> {
    const row = await this.requireDatabase().getFirstAsync<CacheRow>(
      "SELECT encrypted_payload FROM geography_snapshot WHERE cache_key = ?",
      "own-operational-reference",
    );
    if (!row) return null;
    const payload = await this.codec.decrypt(row.encrypted_payload);
    if (
      !Array.isArray(payload.places) ||
      !Array.isArray(payload.routes) ||
      !Array.isArray(payload.distanceLegs) ||
      typeof payload.synchronizedAt !== "string"
    ) {
      throw new Error("INVALID_GEOGRAPHY_CACHE");
    }
    return {
      places: payload.places as unknown as MobilePlace[],
      routes: payload.routes as unknown as MobileRoute[],
      distanceLegs: payload.distanceLegs as unknown as MobileDistanceLeg[],
      synchronizedAt: payload.synchronizedAt,
    };
  }

  async save(snapshot: OperationalGeographySnapshot): Promise<void> {
    const encrypted = await this.codec.encrypt({
      places: [...snapshot.places],
      routes: [...snapshot.routes],
      distanceLegs: [...snapshot.distanceLegs],
      synchronizedAt: snapshot.synchronizedAt,
    });
    await this.requireDatabase().runAsync(
      `INSERT INTO geography_snapshot (cache_key, encrypted_payload, synchronized_at)
       VALUES (?, ?, ?)
       ON CONFLICT(cache_key) DO UPDATE SET
       encrypted_payload = excluded.encrypted_payload,
       synchronized_at = excluded.synchronized_at`,
      "own-operational-reference",
      encrypted,
      snapshot.synchronizedAt,
    );
  }

  private requireDatabase(): SQLite.SQLiteDatabase {
    if (!this.database) throw new Error("GEOGRAPHY_CACHE_NOT_INITIALIZED");
    return this.database;
  }
}
