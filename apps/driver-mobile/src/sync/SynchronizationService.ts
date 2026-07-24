import type { SyncStatus } from "@oromia/shared-types";
import type {
  OfflineCommand,
  OfflineCommandQueue,
} from "../offline/OfflineCommandQueue";

export interface SyncTransport {
  push(
    commands: readonly OfflineCommand[],
  ): Promise<readonly { clientCommandId: string; status: SyncStatus }[]>;
}

export class SynchronizationService {
  constructor(
    private readonly queue: OfflineCommandQueue,
    private readonly transport: SyncTransport,
  ) {}

  async synchronize(): Promise<number> {
    const commands = await this.queue.pending();
    if (commands.length === 0) return 0;
    const results = await this.transport.push(commands);
    for (const result of results) {
      await this.queue.updateStatus(result.clientCommandId, result.status);
    }
    return results.length;
  }
}
