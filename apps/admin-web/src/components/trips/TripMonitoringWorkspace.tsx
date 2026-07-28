"use client";

import { translate, type Locale } from "@oromia/localization";
import Link from "next/link";
import type { Route } from "next";
import { useRouter } from "next/navigation";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type FormEvent,
} from "react";
import { ApiProblem, apiRequest } from "@/platform/api-client";
import type { PagedTrips, TripSummary } from "@/trips/types";

type LoadState =
  | "context"
  | "loading"
  | "ready"
  | "empty"
  | "error"
  | "forbidden";
type Sort = "planned_departure_at" | "-planned_departure_at" | "trip_number" | "-trip_number" | "status" | "-status";
interface OrganizationOption {
  readonly id: string;
  readonly code: string;
  readonly name: Readonly<Record<string, string>>;
  readonly status: string;
}

const emptySummary: PagedTrips["summary"] = {
  total: 0,
  active: 0,
  awaiting_action: 0,
  completed: 0,
  exceptions: 0,
  by_status: {},
};

export function TripMonitoringWorkspace({
  locale,
  organizationId,
}: {
  readonly locale: Locale;
  readonly organizationId?: string;
}) {
  const router = useRouter();
  const t = useCallback(
    (key: Parameters<typeof translate>[1]) => translate(locale, key),
    [locale],
  );
  const [organizations, setOrganizations] = useState<readonly OrganizationOption[]>([]);
  const [organizationState, setOrganizationState] = useState<"loading" | "ready" | "error">("loading");
  const [trips, setTrips] = useState<readonly TripSummary[]>([]);
  const [summary, setSummary] = useState<PagedTrips["summary"]>(emptySummary);
  const [state, setState] = useState<LoadState>(
    organizationId ? "loading" : "context",
  );
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("");
  const [sort, setSort] = useState<Sort>("-planned_departure_at");
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({
    current: 1,
    last: 1,
    total: 0,
    from: null as number | null,
    to: null as number | null,
  });
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    let active = true;
    void apiRequest<{ data: readonly OrganizationOption[] }>("/organizations?filter%5Bstatus%5D=active&page_size=100")
      .then((result) => {
        if (!active) return;
        setOrganizations(result.data);
        setOrganizationState("ready");
      })
      .catch(() => {
        if (active) setOrganizationState("error");
      });
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (!organizationId) return;
    const filters = new URLSearchParams({
      organization_id: organizationId,
      page: String(page),
      page_size: "25",
      sort,
    });
    if (query) filters.set("search", query);
    if (status) filters.set("status", status);
    let active = true;
    void apiRequest<PagedTrips>(`/trips?${filters}`)
      .then((result) => {
        if (!active) return;
        setTrips(result.data);
        setSummary(result.summary);
        setPagination({
          current: result.current_page,
          last: result.last_page,
          total: result.total,
          from: result.from,
          to: result.to,
        });
        setState(result.data.length ? "ready" : "empty");
      })
      .catch((error: unknown) => {
        if (!active) return;
        setState(
          error instanceof ApiProblem && error.problem.status === 403
            ? "forbidden"
            : "error",
        );
      });
    return () => {
      active = false;
    };
  }, [organizationId, page, query, refresh, sort, status]);

  const metrics = useMemo(
    () =>
      [
        [t("tripAdmin.metricTotal"), summary.total, "primary"],
        [t("tripAdmin.metricActive"), summary.active, "information"],
        [t("tripAdmin.metricAwaiting"), summary.awaiting_action, "warning"],
        [t("tripAdmin.metricExceptions"), summary.exceptions, "danger"],
      ] as const,
    [summary, t],
  );

  const selectOrganization = (selectedId: string) => {
    setPage(1);
    if (!selectedId) {
      router.push("/trips");
      return;
    }
    router.push(`/trips?organization_id=${encodeURIComponent(selectedId)}` as Route);
  };

  return (
    <div className="geo-workspace trip-workspace">
      <nav className="breadcrumbs" aria-label={t("nav.breadcrumbs")}>
        <Link href="/">{t("nav.home")}</Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{t("nav.trips")}</span>
      </nav>

      <header className="geo-command-header">
        <div>
          <p className="eyebrow">{t("tripAdmin.eyebrow")}</p>
          <h1 id="trip-register-title">{t("tripAdmin.title")}</h1>
          <p>{t("tripAdmin.description")}</p>
        </div>
        <label className="trip-organization-selector">
          <span>{t("tripAdmin.organization")}</span>
          <select
            aria-describedby="trip-organization-help"
            disabled={organizationState === "loading"}
            onChange={(event) => selectOrganization(event.target.value)}
            value={organizationId ?? ""}
          >
            <option value="">{t("tripAdmin.chooseOrganization")}</option>
            {organizations.map((organization) => (
              <option key={organization.id} value={organization.id}>
                {organization.code} — {organization.name[locale] ?? organization.name.en ?? organization.code}
              </option>
            ))}
          </select>
          <small id="trip-organization-help">
            {organizationState === "error"
              ? t("tripAdmin.organizationLoadError")
              : t("tripAdmin.organizationHelp")}
          </small>
        </label>
      </header>

      {!organizationId ? (
        <section className="geo-context-state" aria-labelledby="trip-register-title">
          <span aria-hidden="true">TO</span>
          <div>
            <h2>{t("tripAdmin.contextHeading")}</h2>
            <p>{t("tripAdmin.contextRequired")}</p>
          </div>
        </section>
      ) : (
        <>
          <section className="geo-metrics" aria-label={t("tripAdmin.snapshot")}>
            {metrics.map(([label, value, tone]) => (
              <article className={`geo-metric geo-${tone}`} key={label}>
                <span>{label}</span>
                <strong>{value}</strong>
                <i aria-hidden="true" />
              </article>
            ))}
          </section>

          <section className="geo-register-shell" aria-labelledby="trip-register-heading">
            <div className="geo-register-heading">
              <div>
                <span className="step-label">{t("tripAdmin.register")}</span>
                <h2 id="trip-register-heading">{t("tripAdmin.registerHeading")}</h2>
              </div>
              <span className="geo-live-stamp">
                <i aria-hidden="true" />
                {t("tripAdmin.serverAuthoritative")}
              </span>
            </div>
            <form
              className="geo-filter-bar trip-filter-bar"
              onSubmit={(event: FormEvent<HTMLFormElement>) => {
                event.preventDefault();
                const data = new FormData(event.currentTarget);
                setState("loading");
                setPage(1);
                setQuery(String(data.get("query") ?? "").trim());
                setStatus(String(data.get("status") ?? ""));
                setSort(String(data.get("sort") ?? "-planned_departure_at") as Sort);
              }}
            >
              <label>
                <span>{t("tripAdmin.search")}</span>
                <input defaultValue={query} name="query" placeholder={t("tripAdmin.searchPlaceholder")} type="search" />
              </label>
              <label>
                <span>{t("tripAdmin.status")}</span>
                <select defaultValue={status} name="status">
                  <option value="">{t("tripAdmin.allStatuses")}</option>
                  {(["assigned", "accepted", "ready", "started", "paused", "arrived", "completed", "cancelled", "declined", "failed", "interrupted"] as const).map((value) => (
                    <option key={value} value={value}>{t(`trip.status.${value}`)}</option>
                  ))}
                </select>
              </label>
              <label>
                <span>{t("tripAdmin.sort")}</span>
                <select defaultValue={sort} name="sort">
                  <option value="-planned_departure_at">{t("tripAdmin.sortNewest")}</option>
                  <option value="planned_departure_at">{t("tripAdmin.sortOldest")}</option>
                  <option value="trip_number">{t("tripAdmin.sortNumber")}</option>
                  <option value="status">{t("tripAdmin.sortStatus")}</option>
                </select>
              </label>
              <button type="submit">{t("tripAdmin.applyFilters")}</button>
            </form>

            <div aria-busy={state === "loading"} aria-live="polite">
              {state === "loading" && <TripSkeleton label={t("state.loading")} />}
              {state === "empty" && <TripState title={t("tripAdmin.emptyTitle")} detail={t("tripAdmin.emptyDetail")} />}
              {state === "forbidden" && <TripState title={t("state.forbidden")} detail={t("tripAdmin.forbiddenDetail")} tone="danger" />}
              {state === "error" && (
                <TripState title={t("state.unavailable")} detail={t("tripAdmin.errorDetail")} tone="warning">
                  <button className="button-secondary" onClick={() => setRefresh((value) => value + 1)} type="button">
                    {t("tripAdmin.retry")}
                  </button>
                </TripState>
              )}
              {state === "ready" && (
                <>
                  <div className="table-scroll trip-register" role="region" aria-label={t("tripAdmin.registerHeading")} tabIndex={0}>
                    <table>
                      <thead>
                        <tr>
                          <th scope="col">{t("tripAdmin.tripNumber")}</th>
                          <th scope="col">{t("tripAdmin.purpose")}</th>
                          <th scope="col">{t("tripAdmin.status")}</th>
                          <th scope="col">{t("tripAdmin.driver")}</th>
                          <th scope="col">{t("tripAdmin.vehicle")}</th>
                          <th scope="col">{t("trip.departure")}</th>
                          <th scope="col">{t("tripAdmin.actions")}</th>
                        </tr>
                      </thead>
                      <tbody>
                        {trips.map((trip) => (
                          <tr key={trip.id}>
                            <td data-label={t("tripAdmin.tripNumber")}><strong>{trip.trip_number}</strong></td>
                            <td data-label={t("tripAdmin.purpose")}>{trip.purpose}</td>
                            <td data-label={t("tripAdmin.status")}><span className={`semantic-badge badge-${statusTone(trip.status)}`}>{t(`trip.status.${trip.status}` as Parameters<typeof translate>[1])}</span></td>
                            <td data-label={t("tripAdmin.driver")}><code>{trip.driver_id}</code></td>
                            <td data-label={t("tripAdmin.vehicle")}><code>{trip.vehicle_id}</code></td>
                            <td data-label={t("trip.departure")}><time dateTime={trip.planned_departure_at}>{new Date(trip.planned_departure_at).toLocaleString(locale)}</time></td>
                            <td data-label={t("tripAdmin.actions")}>
                              <Link aria-label={`${t("tripAdmin.openTrip")} ${trip.trip_number}`} href={`/trips/${trip.id}?organization_id=${organizationId}` as Route}>
                                {t("tripAdmin.openRecord")} <span aria-hidden="true">→</span>
                              </Link>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <nav className="trip-pagination" aria-label={t("tripAdmin.pagination")}>
                    <p>{t("tripAdmin.showing")} {pagination.from}–{pagination.to} {t("tripAdmin.of")} {pagination.total}</p>
                    <div>
                      <button disabled={pagination.current <= 1} onClick={() => { setState("loading"); setPage((value) => value - 1); }} type="button">{t("tripAdmin.previous")}</button>
                      <span aria-current="page">{pagination.current} / {pagination.last}</span>
                      <button disabled={pagination.current >= pagination.last} onClick={() => { setState("loading"); setPage((value) => value + 1); }} type="button">{t("tripAdmin.next")}</button>
                    </div>
                  </nav>
                </>
              )}
            </div>
          </section>
        </>
      )}
    </div>
  );
}

function TripSkeleton({ label }: { readonly label: string }) {
  return <div className="geo-skeleton" role="status" aria-label={label}>{[0, 1, 2].map((item) => <span key={item} />)}</div>;
}

function TripState({ title, detail, tone = "neutral", children }: {
  readonly title: string;
  readonly detail: string;
  readonly tone?: "neutral" | "warning" | "danger";
  readonly children?: React.ReactNode;
}) {
  return <section className={`geo-state geo-state-${tone}`}><span aria-hidden="true">!</span><div><h3>{title}</h3><p>{detail}</p>{children}</div></section>;
}

function statusTone(status: string): "success" | "warning" | "danger" | "information" {
  if (status === "completed") return "success";
  if (["failed", "interrupted", "declined", "cancelled"].includes(status)) return "danger";
  if (["assigned", "accepted", "ready"].includes(status)) return "warning";
  return "information";
}
