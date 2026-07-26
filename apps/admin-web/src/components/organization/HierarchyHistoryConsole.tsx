"use client";

import { useEffect, useState } from "react";
import { translate, type Locale } from "@oromia/localization";
import { apiRequest } from "@/platform/api-client";
import type { ApiEnvelope, AuditEvent } from "@/organization/types";
import { AuditTimeline } from "./OrganizationDetailConsole";

export function HierarchyHistoryConsole({
  locale,
}: {
  readonly locale: Locale;
}) {
  const [events, setEvents] = useState<readonly AuditEvent[] | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    apiRequest<ApiEnvelope<AuditEvent[]>>("/organization-hierarchy/history")
      .then((response) => setEvents(response.data))
      .catch(() => setError(true));
  }, []);

  return (
    <section className="panel">
      <p className="eyebrow">
        {translate(locale, "organization.auditEvidence")}
      </p>
      <h1>{translate(locale, "organization.history")}</h1>
      <p>{translate(locale, "organization.historyDescription")}</p>
      {error ? (
        <div className="state-box" role="alert">
          {translate(locale, "state.unavailable")}
        </div>
      ) : events === null ? (
        <div className="skeleton-block" role="status">
          {translate(locale, "state.loading")}
        </div>
      ) : (
        <AuditTimeline events={events} locale={locale} />
      )}
    </section>
  );
}
