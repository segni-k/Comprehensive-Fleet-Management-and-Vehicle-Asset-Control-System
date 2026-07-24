import "./global.css";

import { useEffect, useMemo, useState } from "react";
import { ScrollView, Text, useWindowDimensions, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import NetInfo from "@react-native-community/netinfo";
import { StatusBar } from "expo-status-bar";
import { catalogues, translate, type Locale } from "@oromia/localization";
import { AccessibleButton } from "./src/components/AccessibleButton";
import {
  PlatformStateCard,
  type FoundationState,
} from "./src/components/PlatformStateCard";
import {
  mobileClassNames,
  resolveMobileLayoutDensity,
} from "./src/theme/tokens";

export default function App() {
  const { fontScale, width } = useWindowDimensions();
  const [locale, setLocale] = useState<Locale>("en");
  const [online, setOnline] = useState(true);
  const [state, setState] = useState<FoundationState>("enrollment_required");
  const t = useMemo(
    () => (key: keyof typeof catalogues.en) => translate(locale, key),
    [locale],
  );
  const density = resolveMobileLayoutDensity(width, fontScale);

  useEffect(
    () =>
      NetInfo.addEventListener((network) =>
        setOnline(Boolean(network.isConnected)),
      ),
    [],
  );

  return (
    <SafeAreaView className={mobileClassNames.screen}>
      <StatusBar style="dark" />
      <ScrollView
        className="flex-1"
        contentContainerClassName={`${mobileClassNames.content} ${density === "compact" ? "gap-4" : ""}`}
        keyboardShouldPersistTaps="handled"
        testID="foundation-screen"
      >
        <View className="gap-3">
          <Text
            accessibilityRole="header"
            className="text-title font-bold text-content dark:text-content-dark"
          >
            {t("app.name")}
          </Text>
          <Text
            accessibilityLiveRegion="polite"
            className={`self-start rounded-pill px-4 py-2 text-body font-semibold ${
              online
                ? "bg-success-soft text-success"
                : "bg-warning-soft text-warning"
            }`}
            testID="network-status"
          >
            {online ? t("state.online") : t("state.offline")}
          </Text>
        </View>

        <View
          className="flex-row flex-wrap gap-3"
          accessibilityRole="radiogroup"
        >
          {(["en", "om", "am"] as const).map((option) => (
            <AccessibleButton
              key={option}
              label={t(`language.${option}`)}
              onPress={() => setLocale(option)}
              selected={locale === option}
            />
          ))}
        </View>

        <PlatformStateCard state={state} translate={t} />

        <View className="gap-3">
          <AccessibleButton
            label={t("auth.signIn")}
            onPress={() => setState("sign_in")}
          />
          <AccessibleButton
            label={t("sync.title")}
            onPress={() => setState("sync")}
          />
          <AccessibleButton
            label={t("support.title")}
            onPress={() => setState("support")}
          />
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}
