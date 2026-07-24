import type { TranslationKey } from "@oromia/localization";
import { translate, type Locale } from "@oromia/localization";

export function StatePanel({
  locale,
  title,
  supportReference,
}: {
  readonly locale: Locale;
  readonly title: TranslationKey;
  readonly supportReference?: string;
}) {
  return (
    <section className="panel" aria-labelledby="state-title">
      <h1 id="state-title">{translate(locale, title)}</h1>
      {supportReference ? (
        <p>
          {translate(locale, "support.reference")}{" "}
          <code>{supportReference}</code>
        </p>
      ) : null}
    </section>
  );
}
