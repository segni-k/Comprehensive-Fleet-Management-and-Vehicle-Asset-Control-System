"use client";

import { translate, type Locale } from "@oromia/localization";
import Link from "next/link";
import type { Route } from "next";
import { useEffect, useState } from "react";
import { ApiProblem, apiRequest } from "@/platform/api-client";
import type { TripDetail } from "@/trips/types";

type DetailState = "context" | "loading" | "ready" | "error" | "forbidden" | "not-found";

export function TripDetailPanel({ locale, tripId, organizationId }: {
  readonly locale: Locale;
  readonly tripId: string;
  readonly organizationId?: string;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const [trip, setTrip] = useState<TripDetail | null>(null);
  const [state, setState] = useState<DetailState>(organizationId ? "loading" : "context");
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    if (!organizationId) return;
    let active = true;
    void apiRequest<{ data: TripDetail }>(`/trips/${tripId}?organization_id=${organizationId}`)
      .then((result) => {
        if (!active) return;
        setTrip(result.data);
        setState("ready");
      })
      .catch((error: unknown) => {
        if (!active) return;
        if (error instanceof ApiProblem && error.problem.status === 403) setState("forbidden");
        else if (error instanceof ApiProblem && error.problem.status === 404) setState("not-found");
        else setState("error");
      });
    return () => {
      active = false;
    };
  }, [organizationId, refresh, tripId]);

  if (!organizationId) {
    return (
      <section className="geo-context-state">
        <span aria-hidden="true">TO</span>
        <div>
          <h1>{t("tripAdmin.contextHeading")}</h1>
          <p>{t("tripAdmin.contextRequired")}</p>
          <Link className="primary-button" href="/trips">{t("tripAdmin.chooseOrganization")}</Link>
        </div>
      </section>
    );
  }

  if (state !== "ready" || !trip) {
    const errorState = state === "ready" ? "error" : state;
    const content = {
      loading: [t("state.loading"), t("tripAdmin.detailDescription")],
      forbidden: [t("state.forbidden"), t("tripAdmin.detailForbidden")],
      "not-found": [t("state.notFound"), t("tripAdmin.detailNotFound")],
      error: [t("state.unavailable"), t("tripAdmin.errorDetail")],
      context: [t("tripAdmin.contextHeading"), t("tripAdmin.contextRequired")],
    }[errorState];
    return (
      <div className="geo-workspace">
        <Link className="button-secondary" href={`/trips?organization_id=${organizationId}` as Route}>← {t("tripAdmin.backToRegister")}</Link>
        <section className={`geo-state geo-state-${errorState === "forbidden" || errorState === "not-found" ? "danger" : "warning"}`} aria-live="polite">
          <span aria-hidden="true">!</span>
          <div>
            <h1>{content[0]}</h1>
            <p>{content[1]}</p>
            {errorState === "error" && <button onClick={() => { setState("loading"); setRefresh((value) => value + 1); }} type="button">{t("tripAdmin.retry")}</button>}
          </div>
        </section>
      </div>
    );
  }

  return (
    <div className="geo-workspace trip-workspace">
      <nav className="breadcrumbs" aria-label={t("nav.breadcrumbs")}>
        <Link href="/">{t("nav.home")}</Link><span aria-hidden="true">/</span>
        <Link href={`/trips?organization_id=${organizationId}` as Route}>{t("nav.trips")}</Link><span aria-hidden="true">/</span>
        <span aria-current="page">{trip.trip_number}</span>
      </nav>
      <header className="geo-command-header">
        <div><p className="eyebrow">{trip.trip_number}</p><h1>{trip.purpose}</h1><p>{t("tripAdmin.detailDescription")}</p></div>
        <span className="semantic-badge badge-information">{t(`trip.status.${trip.status}` as Parameters<typeof translate>[1])}</span>
      </header>
      <section className="geo-metrics" aria-label={t("tripAdmin.snapshot")}>
        {([
          [t("tripAdmin.checklists"), trip.checklists.length, "primary"],
          [t("tripAdmin.checkins"), trip.checkins.length, "information"],
          [t("tripAdmin.events"), trip.events.length, "success"],
          [t("tripAdmin.issues"), trip.issues.length, "warning"],
        ] as const).map(([label, value, tone]) => (
          <article className={`geo-metric geo-${tone}`} key={label}><span>{label}</span><strong>{value}</strong><i aria-hidden="true" /></article>
        ))}
      </section>
      <div className="trip-detail-grid">
        <section className="geo-register-shell">
          <h2>{t("tripAdmin.timeline")}</h2>
          {trip.events.length ? (
            <ol className="trip-timeline">
              {trip.events.map((event) => (
                <li key={event.id}><span aria-hidden="true" /><div><strong>{event.event_type.replaceAll("_", " ")}</strong><time dateTime={event.occurred_at}>{new Date(event.occurred_at).toLocaleString(locale)}</time></div></li>
              ))}
            </ol>
          ) : <p>{t("tripAdmin.noTimeline")}</p>}
        </section>
        <aside className="geo-register-shell">
          <h2>{t("tripAdmin.readings")}</h2>
          <dl className="trip-reading-list">
            {trip.readings.map((reading) => (
              <div key={reading.id}><dt>{reading.phase.replaceAll("_", " ")}</dt><dd>{reading.odometer_km} km</dd><dd><time dateTime={reading.captured_at}>{new Date(reading.captured_at).toLocaleString(locale)}</time></dd></div>
            ))}
          </dl>
        </aside>
      </div>
    </div>
  );
}
