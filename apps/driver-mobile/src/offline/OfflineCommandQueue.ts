import type { SyncStatus } from "@oromia/shared-types";

export interface OfflineCommand {
  readonly clientCommandId: string;
  readonly idempotencyKey: string;
  readonly type: string;
  readonly capturedAt: string;
  readonly sequence: number;
  readonly payload: Readonly<Record<string, unknown>>;
  readonly status: SyncStatus;
}

export interface OfflineCommandQueue {
  enqueue(command: OfflineCommand): Promise<void>;
  pending(limit?: number): Promise<readonly OfflineCommand[]>;
  updateStatus(clientCommandId: string, status: SyncStatus): Promise<void>;
}

export class InMemoryOfflineCommandQueue implements OfflineCommandQueue {
  private readonly commands = new Map<string, OfflineCommand>();

  async enqueue(command: OfflineCommand): Promise<void> {
    if (this.commands.has(command.clientCommandId)) {
      throw new Error("DUPLICATE_CLIENT_COMMAND");
    }
    this.commands.set(command.clientCommandId, command);
  }

  async pending(limit = 50): Promise<readonly OfflineCommand[]> {
    return [...this.commands.values()]
      .filter((command) => command.status === "local_pending")
      .sort((left, right) => left.sequence - right.sequence)
      .slice(0, limit);
  }

  async updateStatus(
    clientCommandId: string,
    status: SyncStatus,
  ): Promise<void> {
    const current = this.commands.get(clientCommandId);
    if (!current) throw new Error("COMMAND_NOT_FOUND");
    this.commands.set(clientCommandId, { ...current, status });
  }
}
