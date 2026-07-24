import { Pressable, Text } from "react-native";

export type ButtonVariant = "primary" | "secondary" | "danger";

interface Props {
  readonly accessibilityHint?: string;
  readonly disabled?: boolean;
  readonly label: string;
  readonly onPress: () => void;
  readonly selected?: boolean;
  readonly variant?: ButtonVariant;
}

const buttonVariants: Record<ButtonVariant, string> = {
  primary: "border-brand bg-brand",
  secondary:
    "border-brand bg-surface dark:border-border-dark dark:bg-surface-dark",
  danger: "border-danger bg-danger",
};

const labelVariants: Record<ButtonVariant, string> = {
  primary: "text-white",
  secondary: "text-brand dark:text-content-dark",
  danger: "text-white",
};

export function AccessibleButton({
  accessibilityHint,
  disabled = false,
  label,
  onPress,
  selected = false,
  variant = "secondary",
}: Props) {
  const resolvedVariant = selected ? "primary" : variant;

  return (
    <Pressable
      accessibilityHint={accessibilityHint}
      accessibilityLabel={label}
      accessibilityRole="button"
      accessibilityState={{ disabled, selected }}
      className={`min-h-touch min-w-touch items-center justify-center rounded-control border-2 px-5 py-3 active:opacity-75 ${buttonVariants[resolvedVariant]} ${disabled ? "border-disabled bg-disabled opacity-60" : ""}`}
      disabled={disabled}
      hitSlop={4}
      onPress={onPress}
    >
      <Text
        className={`text-center text-body font-semibold ${disabled ? "text-disabled-content" : labelVariants[resolvedVariant]}`}
      >
        {label}
      </Text>
    </Pressable>
  );
}
