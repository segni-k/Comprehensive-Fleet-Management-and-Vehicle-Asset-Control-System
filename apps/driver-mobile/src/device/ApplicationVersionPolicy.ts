export type VersionState = "supported" | "forced_update" | "revoked_device";

export function determineVersionState(input: {
  readonly currentVersion: string;
  readonly minimumVersion: string;
  readonly deviceRevoked: boolean;
}): VersionState {
  if (input.deviceRevoked) return "revoked_device";
  return compareVersions(input.currentVersion, input.minimumVersion) < 0
    ? "forced_update"
    : "supported";
}

function compareVersions(left: string, right: string): number {
  const a = left.split(".").map(Number);
  const b = right.split(".").map(Number);
  for (let index = 0; index < Math.max(a.length, b.length); index += 1) {
    const difference = (a[index] ?? 0) - (b[index] ?? 0);
    if (difference !== 0) return difference;
  }
  return 0;
}
