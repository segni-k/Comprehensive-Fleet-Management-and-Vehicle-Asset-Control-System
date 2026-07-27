import "../../global.css";

import { Stack } from "expo-router";
import { SafeAreaProvider } from "react-native-safe-area-context";
import { MobileLocaleProvider } from "../localization/MobileLocaleProvider";

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <MobileLocaleProvider>
        <Stack screenOptions={{ headerShown: false }}>
          <Stack.Screen name="index" />
          <Stack.Screen name="notifications" />
          <Stack.Screen name="approvals" />
          <Stack.Screen name="vehicle" />
          <Stack.Screen name="geography" />
        </Stack>
      </MobileLocaleProvider>
    </SafeAreaProvider>
  );
}
