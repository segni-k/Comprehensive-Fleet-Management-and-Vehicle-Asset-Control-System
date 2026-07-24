import type { NetInfoState } from "@react-native-community/netinfo";

export function isNetworkOnline(
  state: Pick<NetInfoState, "isConnected" | "isInternetReachable">,
): boolean {
  return state.isConnected === true && state.isInternetReachable !== false;
}
