import { am } from "./catalogues/am";
import { en } from "./catalogues/en";
import { om } from "./catalogues/om";

export type TranslationKey = keyof typeof en;
export type Locale = "en" | "om" | "am";

const catalogues = { en, om, am } as const;

export function translate(locale: Locale, key: TranslationKey): string {
  return catalogues[locale][key] ?? en[key] ?? key;
}

export function hasLocale(value: string): value is Locale {
  return value === "en" || value === "om" || value === "am";
}

export { catalogues };
