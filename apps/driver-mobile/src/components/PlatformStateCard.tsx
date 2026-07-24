import { Text, View } from "react-native";
import type { catalogues } from "@oromia/localization";
import { mobileClassNames } from "../theme/tokens";

export type FoundationState =
  | "sign_in"
  | "enrollment_required"
  | "revoked"
  | "forced_update"
  | "sync"
  | "support";

type Key = keyof typeof catalogues.en;

const stateKeys: Record<FoundationState, { title: Key; body: Key }> = {
  sign_in: { title: "auth.signIn", body: "auth.integrationPending" },
  enrollment_required: {
    title: "state.enrollmentRequired",
    body: "support.reference",
  },
  revoked: { title: "state.revokedDevice", body: "support.reference" },
  forced_update: { title: "state.forcedUpdate", body: "support.reference" },
  sync: { title: "sync.title", body: "sync.noPending" },
  support: { title: "support.title", body: "support.reference" },
};

type StateTone = "neutral" | "info" | "warning" | "danger";

const stateTones: Record<FoundationState, StateTone> = {
  sign_in: "neutral",
  enrollment_required: "warning",
  revoked: "danger",
  forced_update: "warning",
  sync: "info",
  support: "neutral",
};

const toneClassNames: Record<StateTone, string> = {
  neutral: "border-border dark:border-border-dark",
  info: "border-info bg-info-soft dark:border-info dark:bg-surface-dark",
  warning:
    "border-warning bg-warning-soft dark:border-warning dark:bg-surface-dark",
  danger:
    "border-danger bg-danger-soft dark:border-danger dark:bg-surface-dark",
};

interface Props {
  readonly state: FoundationState;
  readonly translate: (key: Key) => string;
}

export function PlatformStateCard({ state, translate }: Props) {
  const content = stateKeys[state];
  const tone = stateTones[state];

  return (
    <View
      accessibilityLiveRegion="polite"
      accessibilityRole="summary"
      className={`${mobileClassNames.card} ${toneClassNames[tone]}`}
      testID={`platform-state-${state}`}
    >
      <Text accessibilityRole="header" className={mobileClassNames.heading}>
        {translate(content.title)}
      </Text>
      <Text className={mobileClassNames.body}>{translate(content.body)}</Text>
    </View>
  );
}
