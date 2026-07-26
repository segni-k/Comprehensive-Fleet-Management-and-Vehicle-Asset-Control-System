import Link from "next/link";
import { translate, type Locale } from "@oromia/localization";
import { LanguageSelector } from "./LanguageSelector";

export function AppShell({
  locale,
  children,
}: {
  readonly locale: Locale;
  readonly children: React.ReactNode;
}) {
  return (
    <>
      <a className="skip-link" href="#main-content">
        {translate(locale, "nav.skip")}
      </a>
      <header className="shell-header">
        <Link className="brand" href="/">
          {translate(locale, "app.name")}
        </Link>
        <nav aria-label="Platform" className="shell-nav">
          <Link href="/">{translate(locale, "nav.home")}</Link>
          <Link href="/organizations">
            {translate(locale, "nav.organizations")}
          </Link>
          <a href="/identity">Identity &amp; access</a>
          <Link href="/profile">{translate(locale, "nav.profile")}</Link>
          <Link href="/sign-in">{translate(locale, "auth.signIn")}</Link>
        </nav>
        <LanguageSelector locale={locale} />
      </header>
      <main className="shell-main" id="main-content" tabIndex={-1}>
        {children}
      </main>
    </>
  );
}
