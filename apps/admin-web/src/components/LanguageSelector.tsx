"use client";

import { useRouter } from "next/navigation";
import { translate, type Locale } from "@oromia/localization";

export function LanguageSelector({ locale }: { readonly locale: Locale }) {
  const router = useRouter();

  function selectLocale(nextLocale: Locale) {
    document.cookie = `locale=${nextLocale}; Path=/; Max-Age=31536000; SameSite=Lax; Secure`;
    router.refresh();
  }

  return (
    <label className="language-form">
      <span>{translate(locale, "nav.language")}</span>
      <select
        aria-label={translate(locale, "nav.language")}
        onChange={(event) => selectLocale(event.target.value as Locale)}
        value={locale}
      >
        <option value="en">{translate(locale, "language.en")}</option>
        <option value="om">{translate(locale, "language.om")}</option>
        <option value="am">{translate(locale, "language.am")}</option>
      </select>
    </label>
  );
}
