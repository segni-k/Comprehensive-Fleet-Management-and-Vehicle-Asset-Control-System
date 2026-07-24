import * as SQLite from "expo-sqlite";
import type { SyncStatus } from "@oromia/shared-types";
import type {
  OfflineCommand,
  OfflineCommandQueue,
} from "./OfflineCommandQueue";
import type { EncryptedPayloadCodec } from "./EncryptedPayloadCodec";

interface StoredCommand {
  client_command_id: string;
  idempotency_key: string;
  command_type: string;
  captured_at: string;
  sequence_number: number;
  encrypted_payload: string;
  status: SyncStatus;
}

export class SqliteOfflineCommandQueue implements OfflineCommandQueue {
  private database?: SQLite.SQLiteDatabase;

  constructor(private readonly codec: EncryptedPayloadCodec) {}

  async initialize(): Promise<void> {
    this.database = await SQLite.openDatabaseAsync("offline-commands.db");
    await this.database.execAsync(`
      PRAGMA journal_mode = WAL;
      CREATE TABLE IF NOT EXISTS offline_commands (
        client_command_id TEXT PRIMARY KEY NOT NULL,
        idempotency_key TEXT NOT NULL UNIQUE,
        command_type TEXT NOT NULL,
        captured_at TEXT NOT NULL,
        sequence_number INTEGER NOT NULL UNIQUE,
        encrypted_payload TEXT NOT NULL,
        status TEXT NOT NULL
      );
      CREATE INDEX IF NOT EXISTS offline_commands_status_sequence
      ON offline_commands (status, sequence_number);
    `);
  }

  async enqueue(command: OfflineCommand): Promise<void> {
    const db = this.requireDatabase();
    await db.runAsync(
      `INSERT INTO offline_commands
       (client_command_id, idempotency_key, command_type, captured_at, sequence_number, encrypted_payload, status)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      command.clientCommandId,
      command.idempotencyKey,
      command.type,
      command.capturedAt,
      command.sequence,
      await this.codec.encrypt(command.payload),
      command.status,
    );
  }

  async pending(limit = 50): Promise<readonly OfflineCommand[]> {
    const rows = await this.requireDatabase().getAllAsync<StoredCommand>(
      "SELECT * FROM offline_commands WHERE status = ? ORDER BY sequence_number LIMIT ?",
      "local_pending",
      limit,
    );

    return Promise.all(
      rows.map(async (row) => ({
        clientCommandId: row.client_command_id,
        idempotencyKey: row.idempotency_key,
        type: row.command_type,
        capturedAt: row.captured_at,
        sequence: row.sequence_number,
        payload: await this.codec.decrypt(row.encrypted_payload),
        status: row.status,
      })),
    );
  }

  async updateStatus(
    clientCommandId: string,
    status: SyncStatus,
  ): Promise<void> {
    const result = await this.requireDatabase().runAsync(
      "UPDATE offline_commands SET status = ? WHERE client_command_id = ?",
      status,
      clientCommandId,
    );
    if (result.changes !== 1) throw new Error("COMMAND_NOT_FOUND");
  }

  private requireDatabase(): SQLite.SQLiteDatabase {
    if (!this.database) throw new Error("QUEUE_NOT_INITIALIZED");
    return this.database;
  }
}
