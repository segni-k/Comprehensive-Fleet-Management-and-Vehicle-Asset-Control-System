export const mobileDesignTokens = Object.freeze({
  touchTargetMinimum: 48,
  iconSize: {
    small: 16,
    medium: 24,
    large: 32,
  },
  elevation: {
    flat: 0,
    raised: 2,
    overlay: 6,
  },
  compactWidthMaximum: 359,
  largeFontScaleMinimum: 1.3,
});

export type MobileLayoutDensity = "compact" | "comfortable";

export function resolveMobileLayoutDensity(
  width: number,
  fontScale: number,
): MobileLayoutDensity {
  return width <= mobileDesignTokens.compactWidthMaximum ||
    fontScale >= mobileDesignTokens.largeFontScaleMinimum
    ? "compact"
    : "comfortable";
}

export const mobileClassNames = Object.freeze({
  screen: "flex-1 bg-canvas dark:bg-canvas-dark",
  content:
    "w-full max-w-3xl self-center gap-6 px-screen-compact py-4 sm:px-screen sm:py-6 lg:gap-8",
  card: "gap-3 rounded-card border border-border bg-surface p-4 dark:border-border-dark dark:bg-surface-dark sm:p-6",
  body: "text-body text-content-muted dark:text-content-muted-dark",
  heading: "text-heading font-bold text-content dark:text-content-dark",
});
