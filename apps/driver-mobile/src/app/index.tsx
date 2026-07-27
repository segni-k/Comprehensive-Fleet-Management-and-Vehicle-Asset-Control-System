import { useEffect, useMemo, useState } from "react";
import {
  ScrollView,
  Pressable,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import NetInfo from "@react-native-community/netinfo";
import { StatusBar } from "expo-status-bar";
import { Link } from "expo-router";
import { catalogues, translate } from "@oromia/localization";
import { AccessibleButton } from "../components/AccessibleButton";
import {
  PlatformStateCard,
  type FoundationState,
} from "../components/PlatformStateCard";
import { mobileClassNames, resolveMobileLayoutDensity } from "../theme/tokens";
import { useMobileLocale } from "../localization/MobileLocaleProvider";

export default function App() {
  const { fontScale, width } = useWindowDimensions();
  const { locale, setLocale } = useMobileLocale();
  const [online, setOnline] = useState(true);
  const [state, setState] = useState<FoundationState>("enrollment_required");
  const [authStage, setAuthStage] = useState<"credentials" | "mfa">(
    "credentials",
  );
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

        {state === "sign_in" ? (
          <View
            className="overflow-hidden rounded-card border border-border bg-surface dark:border-border-dark dark:bg-surface-dark"
            accessibilityLabel={t("auth.secureAccess")}
          >
            <View className="bg-brand px-5 py-6">
              <Text className="text-caption font-bold uppercase tracking-widest text-white/80">
                {t("auth.secureAccess")}
              </Text>
              <Text
                accessibilityRole="header"
                className="mt-2 text-title font-bold text-white"
              >
                {authStage === "credentials"
                  ? t("auth.mobileWelcome")
                  : t("auth.mfaTitle")}
              </Text>
              <Text className="mt-2 text-body leading-6 text-white/90">
                {authStage === "credentials"
                  ? t("auth.mobileDescription")
                  : t("auth.mfaDescription")}
              </Text>
            </View>
            <View className="gap-5 p-5">
              {authStage === "credentials" ? (
                <>
                  <View className="gap-2">
                    <Text className="text-body font-semibold text-content dark:text-content-dark">
                      {t("auth.identifier")}
                    </Text>
                    <TextInput
                      accessibilityLabel={t("auth.identifier")}
                      autoCapitalize="none"
                      autoComplete="username"
                      className="min-h-touch rounded-control border border-border bg-canvas px-4 text-body text-content dark:border-border-dark dark:bg-canvas-dark dark:text-content-dark"
                      keyboardType="email-address"
                    />
                  </View>
                  <View className="gap-2">
                    <Text className="text-body font-semibold text-content dark:text-content-dark">
                      {t("auth.password")}
                    </Text>
                    <TextInput
                      accessibilityLabel={t("auth.password")}
                      autoComplete="current-password"
                      className="min-h-touch rounded-control border border-border bg-canvas px-4 text-body text-content dark:border-border-dark dark:bg-canvas-dark dark:text-content-dark"
                      secureTextEntry
                    />
                  </View>
                  <AccessibleButton
                    label={t("auth.continue")}
                    onPress={() => setAuthStage("mfa")}
                  />
                </>
              ) : (
                <>
                  <View className="gap-2">
                    <Text className="text-body font-semibold text-content dark:text-content-dark">
                      {t("auth.verificationCode")}
                    </Text>
                    <TextInput
                      accessibilityLabel={t("auth.verificationCode")}
                      autoComplete="one-time-code"
                      className="min-h-touch rounded-control border border-border bg-canvas px-4 text-center text-heading font-bold tracking-widest text-content dark:border-border-dark dark:bg-canvas-dark dark:text-content-dark"
                      keyboardType="number-pad"
                      maxLength={6}
                    />
                  </View>
                  <AccessibleButton
                    label={t("auth.verify")}
                    onPress={() => setState("sync")}
                  />
                  <AccessibleButton
                    label={t("auth.back")}
                    onPress={() => setAuthStage("credentials")}
                  />
                </>
              )}
              <Text className="text-caption leading-5 text-content-muted dark:text-content-muted-dark">
                {t("auth.protectedNotice")}
              </Text>
            </View>
          </View>
        ) : (
          <PlatformStateCard state={state} translate={t} />
        )}

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
          <Link href="/notifications" asChild>
            <Pressable
              accessibilityLabel={t("operations.notifications")}
              accessibilityRole="button"
              className="min-h-touch justify-center rounded-control border-2 border-brand bg-surface px-5 py-3 active:opacity-75 dark:border-border-dark dark:bg-surface-dark"
            >
              <Text className="text-center text-body font-semibold text-brand dark:text-content-dark">
                {t("operations.notifications")}
              </Text>
            </Pressable>
          </Link>
          <Link href="/vehicle" asChild>
            <Pressable
              accessibilityLabel={t("mobileVehicle.title")}
              accessibilityRole="button"
              className="min-h-touch justify-center rounded-control border-2 border-brand bg-surface px-5 py-3 active:opacity-75 dark:border-border-dark dark:bg-surface-dark"
            >
              <Text className="text-center text-body font-semibold text-brand dark:text-content-dark">
                {t("mobileVehicle.title")}
              </Text>
            </Pressable>
          </Link>
          <Link href="/geography" asChild>
            <Pressable
              accessibilityLabel={t("mobileGeography.open")}
              accessibilityRole="button"
              className="min-h-touch justify-center rounded-control border-2 border-brand bg-surface px-5 py-3 active:opacity-75 dark:border-border-dark dark:bg-surface-dark"
            >
              <Text className="text-center text-body font-semibold text-brand dark:text-content-dark">
                {t("mobileGeography.open")}
              </Text>
            </Pressable>
          </Link>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}
