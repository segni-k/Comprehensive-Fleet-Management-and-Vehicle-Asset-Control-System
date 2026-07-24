export type SupportedLocale = "en" | "om" | "am";

export interface ProblemDetails {
  readonly type: string;
  readonly title: string;
  readonly status: number;
  readonly code: string;
  readonly detail?: string;
  readonly correlation_id: string;
  readonly errors?: Readonly<Record<string, readonly string[]>>;
}

export interface PaginationMeta {
  readonly correlation_id: string;
  readonly next_cursor?: string;
}

export type PlatformAvailability = "available" | "unavailable" | "maintenance";

export type SyncStatus =
  | "local_pending"
  | "uploading"
  | "server_received"
  | "accepted"
  | "accepted_with_warning"
  | "rejected"
  | "conflict"
  | "requires_review";
