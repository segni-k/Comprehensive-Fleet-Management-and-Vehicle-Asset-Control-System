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
  MobilePlace,
  OperationalGeographyCache,
  OperationalGeographyDataSource,
  OperationalGeographySnapshot,
} from "../geography/types";
import { useMobileLocale } from "../localization/MobileLocaleProvider";
import { isNetworkOnline } from "../network/network-state";
import { mobileClassNames, resolveMobileLayoutDensity } from "../theme/tokens";

type ScreenState = "loading" | "ready" | "empty" | "error";
type Register = "routes" | "places";

export function OperationalGeographyWorkspace({
  cache,
  dataSource,
}: {
  readonly cache: OperationalGeographyCache;
  readonly dataSource: OperationalGeographyDataSource;
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
  const [register, setRegister] = useState<Register>("routes");
  const [snapshot, setSnapshot] =
    useState<OperationalGeographySnapshot | null>(null);

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
          setState(
            cached.routes.length || cached.places.length ? "ready" : "empty",
          );
        }
        const network = await NetInfo.fetch();
        if (!isNetworkOnline(network)) {
          if (active && !cached) setState("empty");
          return;
        }
        const fresh = await dataSource.load(controller.signal);
        await cache.save(fresh);
        if (active) {
          setSnapshot(fresh);
          setState(
            fresh.routes.length || fresh.places.length ? "ready" : "empty",
          );
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

  const placeIndex = useMemo(
    () => new Map(snapshot?.places.map((place) => [place.id, place]) ?? []),
    [snapshot],
  );

  return (
    <SafeAreaView className={mobileClassNames.screen}>
      <ScrollView
        className="flex-1"
        contentContainerClassName={`${mobileClassNames.content} ${
          density === "compact" ? "gap-4" : "gap-7"
        }`}
        testID="operational-geography-screen"
      >
        <View className="gap-2">
          <Text className="text-caption font-bold uppercase tracking-widest text-brand">
            {t("mobileGeography.eyebrow")}
          </Text>
          <Text
            accessibilityRole="header"
            className="text-title font-bold text-content dark:text-content-dark"
          >
            {t("mobileGeography.title")}
          </Text>
          <Text className={mobileClassNames.body}>
            {t("mobileGeography.description")}
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
            className={`flex-1 text-body font-semibold ${
              online ? "text-success" : "text-warning"
            }`}
          >
            {online ? t("mobileGeography.online") : t("mobileGeography.offline")}
          </Text>
        </View>

        <View className="rounded-control border border-information bg-information-soft p-4">
          <Text className="text-body font-semibold leading-6 text-information">
            {t("mobileGeography.locationBoundary")}
          </Text>
        </View>

        <View
          accessibilityRole="tablist"
          className="flex-row gap-2 rounded-control bg-surface-muted p-1"
        >
          {(["routes", "places"] as const).map((item) => (
            <Pressable
              accessibilityRole="tab"
              accessibilityState={{ selected: register === item }}
              className={`min-h-touch flex-1 items-center justify-center rounded-control px-3 py-2 active:opacity-75 ${
                register === item ? "bg-brand" : "bg-transparent"
              }`}
              key={item}
              onPress={() => setRegister(item)}
            >
              <Text
                className={`text-center text-body font-bold ${
                  register === item ? "text-white" : "text-content"
                }`}
              >
                {t(`mobileGeography.${item}`)}
              </Text>
            </Pressable>
          ))}
        </View>

        {state === "loading" && (
          <View
            accessibilityLabel={t("state.loading")}
            accessibilityRole="progressbar"
            className="gap-3"
          >
            <View className="h-40 rounded-card bg-surface-muted" />
            <View className="h-28 rounded-card bg-surface-muted" />
          </View>
        )}
        {state === "error" && (
          <GeographyState
            detail={t("mobileGeography.errorDetail")}
            title={t("mobileGeography.errorTitle")}
            tone="danger"
          />
        )}
        {state === "empty" && (
          <GeographyState
            detail={t("mobileGeography.emptyDetail")}
            title={t("mobileGeography.emptyTitle")}
            tone="neutral"
          />
        )}
        {state === "ready" && register === "routes" && (
          <View className="gap-4">
            {snapshot?.routes.map((route) => {
              const version = route.versions[0];
              return (
                <View
                  className={mobileClassNames.card}
                  key={route.id}
                  accessibilityLabel={localized(route.name, locale)}
                >
                  <View className="flex-row flex-wrap items-start justify-between gap-3">
                    <View className="min-w-0 flex-1 gap-1">
                      <Text className="text-caption font-bold uppercase tracking-wider text-brand">
                        {route.code}
                      </Text>
                      <Text
                        accessibilityRole="header"
                        className="text-heading font-bold text-content dark:text-content-dark"
                      >
                        {localized(route.name, locale)}
                      </Text>
                    </View>
                    <View className="rounded-pill bg-success-soft px-3 py-2">
                      <Text className="text-caption font-bold text-success">
                        {route.directional
                          ? t("mobileGeography.directional")
                          : t("mobileGeography.nonDirectional")}
                      </Text>
                    </View>
                  </View>
                  <View className="flex-row flex-wrap gap-3">
                    <Fact
                      label={t("geography.origin")}
                      value={placeName(placeIndex.get(route.origin_place_id), locale)}
                    />
                    <Fact
                      label={t("geography.destination")}
                      value={placeName(
                        placeIndex.get(route.destination_place_id),
                        locale,
                      )}
                    />
                  </View>
                  {version && (
                    <>
                      <View className="flex-row flex-wrap gap-3">
                        <Fact
                          label={t("mobileGeography.distance")}
                          value={`${version.estimated_distance_km} km`}
                        />
                        <Fact
                          label={t("mobileGeography.duration")}
                          value={`${version.estimated_duration_minutes} min`}
                        />
                      </View>
                      <View className="gap-3 border-t border-border pt-4 dark:border-border-dark">
                        <Text className="text-body font-bold text-content dark:text-content-dark">
                          {t("mobileGeography.stops")}
                        </Text>
                        {version.segments.map((segment) => (
                          <View
                            className="flex-row items-start gap-3"
                            key={segment.id}
                          >
                            <View className="mt-1 h-4 w-4 rounded-full border-4 border-information-soft bg-information" />
                            <View className="min-w-0 flex-1">
                              <Text className="text-body font-semibold text-content dark:text-content-dark">
                                {placeName(
                                  placeIndex.get(segment.destination_place_id),
                                  locale,
                                )}
                              </Text>
                              <Text className="text-caption leading-5 text-content-muted dark:text-content-muted-dark">
                                {segment.distance_km} km ·{" "}
                                {segment.duration_minutes} min ·{" "}
                                {segment.mandatory_stop
                                  ? t("mobileGeography.mandatoryStop")
                                  : t("mobileGeography.noMandatoryStop")}
                              </Text>
                            </View>
                          </View>
                        ))}
                      </View>
                    </>
                  )}
                </View>
              );
            })}
          </View>
        )}
        {state === "ready" && register === "places" && (
          <View className="gap-3">
            {snapshot?.places.map((place) => (
              <View className={mobileClassNames.card} key={place.id}>
                <View className="flex-row items-start gap-3">
                  <View className="h-12 w-12 items-center justify-center rounded-full bg-information-soft">
                    <Text className="text-heading font-bold text-information">
                      ·
                    </Text>
                  </View>
                  <View className="min-w-0 flex-1 gap-1">
                    <Text className="text-caption font-bold uppercase tracking-wider text-brand">
                      {place.code}
                    </Text>
                    <Text
                      accessibilityRole="header"
                      className="text-heading font-bold text-content dark:text-content-dark"
                    >
                      {localized(place.name, locale)}
                    </Text>
                    {place.latitude && place.longitude && (
                      <Text className="text-caption leading-5 text-content-muted dark:text-content-muted-dark">
                        {place.latitude}, {place.longitude}
                      </Text>
                    )}
                  </View>
                </View>
              </View>
            ))}
          </View>
        )}

        {snapshot && (
          <Text className="text-caption leading-5 text-content-muted dark:text-content-muted-dark">
            {t("mobileGeography.lastSynchronized")}{" "}
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

function Fact({
  label,
  value,
}: {
  readonly label: string;
  readonly value: string;
}) {
  return (
    <View className="min-w-32 flex-1 gap-1 rounded-control bg-canvas p-3 dark:bg-canvas-dark">
      <Text className="text-caption font-semibold text-content-muted dark:text-content-muted-dark">
        {label}
      </Text>
      <Text className="text-body font-bold text-content dark:text-content-dark">
        {value}
      </Text>
    </View>
  );
}

function GeographyState({
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

function localized(
  name: Readonly<Record<"en" | "om" | "am", string>>,
  locale: "en" | "om" | "am",
): string {
  return name[locale] || name.en;
}

function placeName(
  place: MobilePlace | undefined,
  locale: "en" | "om" | "am",
): string {
  return place ? localized(place.name, locale) : "—";
}
