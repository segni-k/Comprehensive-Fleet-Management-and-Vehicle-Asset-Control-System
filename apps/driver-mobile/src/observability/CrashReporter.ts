export interface CrashReporter {
  capture(error: Error, context?: Readonly<Record<string, string>>): void;
}
