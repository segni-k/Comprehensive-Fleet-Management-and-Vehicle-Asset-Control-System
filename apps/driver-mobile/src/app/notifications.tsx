import { useMemo, useState } from "react";
import { Pressable, ScrollView, Text, View } from "react-native";
import { Link } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";
import { catalogues, translate } from "@oromia/localization";
import { AccessibleButton } from "../components/AccessibleButton";
import { mobileClassNames } from "../theme/tokens";
import { useMobileLocale } from "../localization/MobileLocaleProvider";

export default function NotificationsScreen() {
  const { locale } = useMobileLocale();
  const [unreadOnly, setUnreadOnly] = useState(false);
  const t = useMemo(
    () => (key: keyof typeof catalogues.en) => translate(locale, key),
    [locale],
  );

  return (
    <SafeAreaView className={mobileClassNames.screen}>
      <ScrollView
        className="flex-1"
        contentContainerClassName={mobileClassNames.content}
        contentInsetAdjustmentBehavior="automatic"
      >
        <View className="gap-2">
          <Text className="text-caption font-bold uppercase tracking-widest text-brand">
            {t("operations.assurance")}
          </Text>
          <Text
            accessibilityRole="header"
            className="text-title font-bold text-content dark:text-content-dark"
          >
            {t("operations.notifications")}
          </Text>
          <Text className={mobileClassNames.body}>
            {t("operations.notificationsDescription")}
          </Text>
        </View>

        <View className="rounded-control bg-warning-soft px-4 py-3">
          <Text
            accessibilityLiveRegion="polite"
            className="text-body font-semibold text-warning"
          >
            {t("operations.offlineNotice")}
          </Text>
          <Text className="mt-1 text-caption text-warning">
            {t("operations.lastUpdated")}
          </Text>
        </View>

        <View className="flex-row flex-wrap gap-3">
          <AccessibleButton
            label={t("operations.unread")}
            onPress={() => setUnreadOnly(true)}
            selected={unreadOnly}
          />
          <AccessibleButton
            label={t("operations.read")}
            onPress={() => setUnreadOnly(false)}
            selected={!unreadOnly}
          />
        </View>

        <View className="gap-3" accessibilityRole="list">
          <Link href="/approvals" asChild>
            <Pressable
              accessibilityLabel={`${t("operations.workflowAssigned")}. ${t("operations.ready")}`}
              accessibilityRole="button"
              className={`${mobileClassNames.card} active:opacity-75`}
            >
              <View className="flex-row items-start justify-between gap-3">
                <Text className="flex-1 text-heading font-bold text-content dark:text-content-dark">
                  {t("operations.workflowAssigned")}
                </Text>
                <Text className="rounded-pill bg-info-soft px-3 py-1 text-caption font-bold text-info">
                  {t("operations.ready")}
                </Text>
              </View>
              <Text className={mobileClassNames.body}>
                {t("operations.workflowAssignedBody")}
              </Text>
            </Pressable>
          </Link>
          {!unreadOnly ? (
            <View className={mobileClassNames.card} accessibilityRole="summary">
              <Text className="text-heading font-bold text-content dark:text-content-dark">
                {t("operations.documentTrusted")}
              </Text>
              <Text className={mobileClassNames.body}>
                {t("operations.documentTrustedBody")}
              </Text>
              <Text className="text-caption font-semibold text-success">
                {t("operations.read")}
              </Text>
            </View>
          ) : null}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}
