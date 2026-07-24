import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const projectRoot = resolve(__dirname, "../..");

describe("NativeWind configuration", () => {
  it("connects Metro, PostCSS, CSS tokens, and React Native CSS types", () => {
    expect(
      readFileSync(resolve(projectRoot, "metro.config.js"), "utf8"),
    ).toContain("withNativewind");
    expect(
      readFileSync(resolve(projectRoot, "postcss.config.mjs"), "utf8"),
    ).toContain("@tailwindcss/postcss");
    const css = readFileSync(resolve(projectRoot, "global.css"), "utf8");
    expect(css).toContain('@import "nativewind/theme"');
    expect(css).toContain("--color-canvas-dark");
    expect(css).toContain("--spacing-touch");
    expect(
      readFileSync(resolve(projectRoot, "nativewind-env.d.ts"), "utf8"),
    ).toContain("react-native-css/types");
  });

  it("keeps foundation components NativeWind-first", () => {
    for (const file of [
      "App.tsx",
      "src/components/AccessibleButton.tsx",
      "src/components/PlatformStateCard.tsx",
    ]) {
      const source = readFileSync(resolve(projectRoot, file), "utf8");
      expect(source).not.toContain("StyleSheet.create");
      expect(source).toContain("className");
    }
  });
});
