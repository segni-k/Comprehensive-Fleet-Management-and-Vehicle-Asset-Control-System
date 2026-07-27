import { useMemo, useState } from "react";
import { Pressable, ScrollView, Text, TextInput, View } from "react-native";
import { Link } from "expo-router";
import { SafeAreaView } from "react-native-safe-area-context";
import { catalogues, translate } from "@oromia/localization";
import { AccessibleButton } from "../components/AccessibleButton";
import { mobileClassNames } from "../theme/tokens";
import { useMobileLocale } from "../localization/MobileLocaleProvider";

export default function ApprovalScreen() {
  const { locale } = useMobileLocale();
  const [reason, setReason] = useState("");
  const t = useMemo(
    () => (key: keyof typeof catalogues.en) => translate(locale, key),
    [locale],
  );

  return (
    <SafeAreaView className={mobileClassNames.screen}>
      <ScrollView
        className="flex-1"
        contentContainerClassName={mobileClassNames.content}
        keyboardShouldPersistTaps="handled"
      >
        <Link href="/notifications" asChild>
          <Pressable
            accessibilityRole="button"
            className="min-h-touch self-start justify-center active:opacity-75"
          >
            <Text className="text-body font-bold text-brand">
              {t("auth.back")}
            </Text>
          </Pressable>
        </Link>
        <View className="gap-2">
          <Text className="text-caption font-bold uppercase tracking-widest text-brand">
            {t("operations.reviewTask")}
          </Text>
          <Text
            accessibilityRole="header"
            className="text-title font-bold text-content dark:text-content-dark"
          >
            {t("operations.workflowAssigned")}
          </Text>
          <Text className={mobileClassNames.body}>
            {t("operations.taskScope")}
          </Text>
        </View>

        <View className="overflow-hidden rounded-card border border-border bg-surface dark:border-border-dark dark:bg-surface-dark">
          <View className="border-b border-border p-5 dark:border-border-dark">
            <Text className="text-caption font-bold uppercase tracking-wider text-content-muted dark:text-content-muted-dark">
              {t("operations.evidence")}
            </Text>
            <Text className="mt-2 text-heading font-bold text-content dark:text-content-dark">
              {t("operations.documentTrusted")}
            </Text>
            <Text className={`mt-2 ${mobileClassNames.body}`}>
              {t("operations.documentTrustedBody")}
            </Text>
          </View>
          <View className="p-5">
            <Text className="text-caption font-bold uppercase tracking-wider text-content-muted dark:text-content-muted-dark">
              {t("operations.history")}
            </Text>
            <View className="mt-3 border-l-2 border-brand pl-4">
              <Text className="text-body font-semibold text-content dark:text-content-dark">
                {t("operations.ready")}
              </Text>
              <Text className="text-caption text-content-muted dark:text-content-muted-dark">
                {t("operations.lastUpdated")}
              </Text>
            </View>
          </View>
        </View>

        <View className="gap-2">
          <Text className="text-body font-semibold text-content dark:text-content-dark">
            {t("organization.reason")}
          </Text>
          <TextInput
            accessibilityLabel={t("organization.reason")}
            className="min-h-32 rounded-control border border-border bg-surface px-4 py-3 text-body text-content dark:border-border-dark dark:bg-surface-dark dark:text-content-dark"
            multiline
            onChangeText={setReason}
            textAlignVertical="top"
            value={reason}
          />
        </View>
        <AccessibleButton
          disabled={reason.trim().length < 3}
          label={t("operations.approve")}
          onPress={() => undefined}
          variant="primary"
        />
        <AccessibleButton
          disabled={reason.trim().length < 3}
          label={t("operations.return")}
          onPress={() => undefined}
          variant="secondary"
        />
      </ScrollView>
    </SafeAreaView>
  );
}
