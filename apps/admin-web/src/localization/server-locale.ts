import { cookies } from "next/headers";
import { hasLocale, type Locale } from "@oromia/localization";

export async function getServerLocale(): Promise<Locale> {
  const value = (await cookies()).get("locale")?.value;
  return value && hasLocale(value) ? value : "en";
}
