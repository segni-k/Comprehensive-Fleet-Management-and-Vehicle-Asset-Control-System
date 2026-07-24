"use client";

import { translate } from "@oromia/localization";

export default function GlobalError({
  error,
}: {
  readonly error: Error & { digest?: string };
}) {
  return (
    <html lang="en">
      <body>
        <main className="shell-main">
          <section className="panel" role="alert">
            <h1>{translate("en", "state.serviceUnavailable")}</h1>
            {error.digest ? (
              <p>
                {translate("en", "support.reference")}{" "}
                <code>{error.digest}</code>
              </p>
            ) : null}
          </section>
        </main>
      </body>
    </html>
  );
}
