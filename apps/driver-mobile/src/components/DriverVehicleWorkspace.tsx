import NetInfo from "@react-native-community/netinfo";
import { catalogues, translate } from "@oromia/localization";
import { useEffect, useMemo, useState } from "react";
import {
  Pressable,
  ScrollView,
  Text,
  useWindowDimensions,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import type {
  DriverAssignmentCache,
  DriverAssignmentDataSource,
  DriverAssignmentSnapshot,
  DriverVehicleAssignment,
} from "../assignments/types";
import { useMobileLocale } from "../localization/MobileLocaleProvider";
import { isNetworkOnline } from "../network/network-state";
import { mobileClassNames, resolveMobileLayoutDensity } from "../theme/tokens";

type ScreenState = "loading" | "ready" | "empty" | "error";

export function DriverVehicleWorkspace({
  cache,
  dataSource,
}: {
  readonly cache: DriverAssignmentCache;
  readonly dataSource: DriverAssignmentDataSource;
}) {
  const { locale } = useMobileLocale();
  const { width, fontScale } = useWindowDimensions();
  const density = resolveMobileLayoutDensity(width, fontScale);
  const t = useMemo(
    () => (key: keyof typeof catalogues.en) => translate(locale, key),
    [locale],
  );
  const [online, setOnline] = useState(true);
  const [state, setState] = useState<ScreenState>("loading");
  const [snapshot, setSnapshot] = useState<DriverAssignmentSnapshot | null>(
    null,
  );
  const [acknowledging, setAcknowledging] = useState<string | null>(null);
  const [acknowledgementFailed, setAcknowledgementFailed] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    const unsubscribe = NetInfo.addEventListener((network) =>
      setOnline(isNetworkOnline(network)),
    );
    void cache
      .initialize()
      .then(async () => {
        const cached = await cache.load();
        if (active && cached) {
          setSnapshot(cached);
          setState(cached.assignments.length ? "ready" : "empty");
        }
        const network = await NetInfo.fetch();
        if (!isNetworkOnline(network)) {
          if (active && !cached) setState("empty");
          return;
        }
        const fresh = await dataSource.list(controller.signal);
        await cache.save(fresh);
        if (active) {
          setSnapshot(fresh);
          setState(fresh.assignments.length ? "ready" : "empty");
        }
      })
      .catch((error: unknown) => {
        if (
          active &&
          (!(error instanceof Error) || error.name !== "AbortError")
        ) {
          setState((current) =>
            current === "ready" || current === "empty" ? current : "error",
          );
        }
      });
    return () => {
      active = false;
      controller.abort();
      unsubscribe();
    };
  }, [cache, dataSource]);

  async function acknowledge(assignment: DriverVehicleAssignment) {
    if (!online || acknowledging) return;
    setAcknowledgementFailed(false);
    setAcknowledging(assignment.id);
    try {
      const updated = await dataSource.acknowledge(assignment.id);
      const next: DriverAssignmentSnapshot = {
        assignments: snapshot?.assignments.map((item) =>
          item.id === updated.id ? updated : item,
        ) ?? [updated],
        synchronizedAt: new Date().toISOString(),
      };
      await cache.save(next);
      setSnapshot(next);
    } catch {
      setAcknowledgementFailed(true);
    } finally {
      setAcknowledging(null);
    }
  }

  return (
    <SafeAreaView className={mobileClassNames.screen}>
      <ScrollView
        className="flex-1"
        contentContainerClassName={`${mobileClassNames.content} ${
          density === "compact" ? "gap-4" : "gap-7"
        }`}
        testID="driver-vehicle-screen"
      >
        <View className="gap-2">
          <Text className="text-caption font-bold uppercase tracking-widest text-brand">
            {t("mobileVehicle.eyebrow")}
          </Text>
          <Text
            accessibilityRole="header"
            className="text-title font-bold text-content dark:text-content-dark"
          >
            {t("mobileVehicle.title")}
          </Text>
          <Text className={mobileClassNames.body}>
            {t("mobileVehicle.description")}
          </Text>
        </View>

        <View
          accessibilityLiveRegion="polite"
          className={`flex-row flex-wrap items-center gap-2 rounded-control px-4 py-3 ${
            online ? "bg-success-soft" : "bg-warning-soft"
          }`}
        >
          <View
            className={`h-3 w-3 rounded-full ${online ? "bg-success" : "bg-warning"}`}
          />
          <Text
            className={`text-body font-semibold ${
              online ? "text-success" : "text-warning"
            }`}
          >
            {online
              ? t("mobileVehicle.synchronized")
              : t("mobileVehicle.offlineCached")}
          </Text>
        </View>

        {state === "loading" && (
          <View
            accessibilityLabel={t("state.loading")}
            className="gap-3"
            accessibilityRole="progressbar"
          >
            <View className="h-32 rounded-card bg-surface-muted" />
            <View className="h-24 rounded-card bg-surface-muted" />
          </View>
        )}
        {state === "error" && (
          <MobileState
            detail={t("mobileVehicle.errorDetail")}
            title={t("state.unavailable")}
            tone="danger"
          />
        )}
        {state === "empty" && (
          <MobileState
            detail={t("mobileVehicle.emptyDetail")}
            title={t("mobileVehicle.emptyTitle")}
            tone="neutral"
          />
        )}
        {state === "ready" &&
          snapshot?.assignments.map((assignment) => (
            <AssignmentCard
              acknowledging={acknowledging === assignment.id}
              assignment={assignment}
              key={assignment.id}
              locale={locale}
              online={online}
              onAcknowledge={() => void acknowledge(assignment)}
            />
          ))}

        {acknowledgementFailed && (
          <MobileState
            detail={t("mobileVehicle.acknowledgementErrorDetail")}
            title={t("mobileVehicle.acknowledgementError")}
            tone="danger"
          />
        )}

        {snapshot && (
          <Text className="text-caption leading-5 text-content-muted dark:text-content-muted-dark">
            {t("mobileVehicle.lastSynchronized")}{" "}
            {new Intl.DateTimeFormat(locale, {
              dateStyle: "medium",
              timeStyle: "short",
            }).format(new Date(snapshot.synchronizedAt))}
          </Text>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function AssignmentCard({
  assignment,
  online,
  acknowledging,
  onAcknowledge,
  locale,
}: {
  readonly assignment: DriverVehicleAssignment;
  readonly online: boolean;
  readonly acknowledging: boolean;
  readonly onAcknowledge: () => void;
  readonly locale: "en" | "om" | "am";
}) {
  const t = (key: keyof typeof catalogues.en) => translate(locale, key);
  const pending =
    assignment.acknowledgement_required && !assignment.acknowledged_at;
  return (
    <View className="overflow-hidden rounded-card border border-border bg-surface dark:border-border-dark dark:bg-surface-dark">
      <View className="bg-brand px-5 py-5">
        <Text className="text-caption font-bold uppercase tracking-wider text-white/80">
          {t("mobileVehicle.assignedVehicle")}
        </Text>
        <Text
          accessibilityRole="header"
          className="mt-2 text-title font-bold text-white"
        >
          {assignment.vehicle?.plate_number ??
            assignment.vehicle?.asset_number ??
            t("fleet.unassigned")}
        </Text>
        <Text className="mt-1 text-body text-white/90">
          {assignment.vehicle?.asset_number ?? t("fleet.notRecorded")}
        </Text>
      </View>
      <View className="gap-5 p-5">
        <View className="flex-row flex-wrap gap-3">
          <Fact
            label={t("fleet.assignmentType")}
            value={assignment.assignment_type.replaceAll("_", " ")}
          />
          <Fact
            label={t("fleet.status")}
            value={assignment.status.replaceAll("_", " ")}
          />
          <Fact
            label={t("fleet.startsAt")}
            value={new Intl.DateTimeFormat(locale, {
              dateStyle: "medium",
            }).format(new Date(assignment.starts_at))}
          />
        </View>
        <View className="rounded-control border border-border bg-canvas p-4 dark:border-border-dark dark:bg-canvas-dark">
          <Text className="text-body font-bold text-content dark:text-content-dark">
            {pending
              ? t("mobileVehicle.acknowledgementPending")
              : t("mobileVehicle.acknowledged")}
          </Text>
          <Text className="mt-2 text-caption leading-5 text-content-muted dark:text-content-muted-dark">
            {pending
              ? t("mobileVehicle.acknowledgementDetail")
              : t("mobileVehicle.acknowledgedDetail")}
          </Text>
        </View>
        <View className="gap-3">
          <Text className="text-body font-bold text-content dark:text-content-dark">
            {t("mobileVehicle.documentStatus")}
          </Text>
          {assignment.vehicle?.compliance.length ? (
            assignment.vehicle.compliance.map((record) => (
              <View
                className={`flex-row flex-wrap items-center justify-between gap-2 rounded-control px-4 py-3 ${
                  record.status === "current"
                    ? "bg-success-soft"
                    : "bg-danger-soft"
                }`}
                key={`${record.document_type}-${record.expires_on ?? "none"}`}
              >
                <Text className="text-body font-semibold capitalize text-content">
                  {record.document_type.replaceAll("_", " ")}
                </Text>
                <Text
                  className={`text-caption font-bold ${
                    record.status === "current" ? "text-success" : "text-danger"
                  }`}
                >
                  {record.status === "current"
                    ? t("mobileVehicle.documentCurrent")
                    : t("mobileVehicle.documentExpired")}
                </Text>
              </View>
            ))
          ) : (
            <Text className="text-caption leading-5 text-content-muted dark:text-content-muted-dark">
              {t("mobileVehicle.noDocumentIndicators")}
            </Text>
          )}
        </View>
        {pending && (
          <Pressable
            accessibilityHint={
              online
                ? t("mobileVehicle.acknowledgeHint")
                : t("mobileVehicle.offlineAcknowledgeHint")
            }
            accessibilityRole="button"
            className={`min-h-touch items-center justify-center rounded-control px-5 py-3 ${
              online ? "bg-brand active:bg-brand-strong" : "bg-disabled"
            }`}
            disabled={!online || acknowledging}
            onPress={onAcknowledge}
          >
            <Text
              className={`text-body font-bold ${
                online ? "text-white" : "text-disabled-content"
              }`}
            >
              {acknowledging
                ? t("mobileVehicle.acknowledging")
                : t("mobileVehicle.acknowledge")}
            </Text>
          </Pressable>
        )}
      </View>
    </View>
  );
}

function Fact({
  label,
  value,
}: {
  readonly label: string;
  readonly value: string;
}) {
  return (
    <View className="min-w-32 flex-1 gap-1">
      <Text className="text-caption font-semibold text-content-muted dark:text-content-muted-dark">
        {label}
      </Text>
      <Text className="text-body font-bold capitalize text-content dark:text-content-dark">
        {value}
      </Text>
    </View>
  );
}

function MobileState({
  title,
  detail,
  tone,
}: {
  readonly title: string;
  readonly detail: string;
  readonly tone: "neutral" | "danger";
}) {
  return (
    <View
      className={`gap-2 rounded-card border p-5 ${
        tone === "danger"
          ? "border-danger bg-danger-soft"
          : "border-border bg-surface dark:border-border-dark dark:bg-surface-dark"
      }`}
    >
      <Text className="text-heading font-bold text-content dark:text-content-dark">
        {title}
      </Text>
      <Text className={mobileClassNames.body}>{detail}</Text>
    </View>
  );
}
