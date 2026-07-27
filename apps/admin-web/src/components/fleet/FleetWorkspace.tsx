"use client";

import { translate, type Locale } from "@oromia/localization";
import type { Route } from "next";
import Link from "next/link";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type FormEvent,
} from "react";
import { ApiProblem, apiRequest } from "@/platform/api-client";
import type {
  ApiEnvelope,
  AssignmentSummary,
  DriverSummary,
  FleetDashboard,
  PagedEnvelope,
  VehicleSummary,
} from "@/fleet/types";

type Register = "vehicles" | "drivers" | "assignments";
type LoadState =
  | "context"
  | "loading"
  | "ready"
  | "empty"
  | "forbidden"
  | "error";

export function FleetWorkspace({
  locale,
  organizationId,
}: {
  readonly locale: Locale;
  readonly organizationId?: string;
}) {
  const t = useCallback(
    (key: Parameters<typeof translate>[1]) => translate(locale, key),
    [locale],
  );
  const [register, setRegister] = useState<Register>("vehicles");
  const [state, setState] = useState<LoadState>(
    organizationId ? "loading" : "context",
  );
  const [dashboard, setDashboard] = useState<FleetDashboard | null>(null);
  const [vehicles, setVehicles] = useState<readonly VehicleSummary[]>([]);
  const [drivers, setDrivers] = useState<readonly DriverSummary[]>([]);
  const [assignments, setAssignments] = useState<readonly AssignmentSummary[]>(
    [],
  );
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("");

  useEffect(() => {
    if (!organizationId) return;
    let active = true;
    const params = new URLSearchParams({ organization_id: organizationId });
    if (query) params.set("query", query);
    if (status) params.set("status", status);
    Promise.all([
      apiRequest<ApiEnvelope<FleetDashboard>>(
        `/fleet/dashboard?organization_id=${organizationId}`,
      ),
      apiRequest<PagedEnvelope<VehicleSummary>>(`/vehicles?${params}`),
      apiRequest<PagedEnvelope<DriverSummary>>(`/drivers?${params}`),
      apiRequest<PagedEnvelope<AssignmentSummary>>(
        `/vehicle-driver-assignments?organization_id=${organizationId}${status ? `&status=${status}` : ""}`,
      ),
    ])
      .then(([summary, vehicleData, driverData, assignmentData]) => {
        if (!active) return;
        setDashboard(summary.data);
        setVehicles(vehicleData.data);
        setDrivers(driverData.data);
        setAssignments(assignmentData.data);
        setState(
          vehicleData.data.length +
            driverData.data.length +
            assignmentData.data.length ===
            0
            ? "empty"
            : "ready",
        );
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
  }, [organizationId, query, status]);

  const counts = useMemo(
    () =>
      [
        [t("fleet.metricRegistered"), dashboard?.vehicles.total ?? 0, "fleet"],
        [t("fleet.metricActive"), dashboard?.vehicles.active ?? 0, "success"],
        [
          t("fleet.metricAvailableDrivers"),
          dashboard?.drivers.available ?? 0,
          "information",
        ],
        [
          t("fleet.metricComplianceAttention"),
          (dashboard?.compliance.expiring_30_days ?? 0) +
            (dashboard?.compliance.expired ?? 0),
          "warning",
        ],
      ] as const,
    [dashboard, t],
  );

  if (!organizationId) {
    return (
      <section className="fleet-context-state" aria-labelledby="fleet-title">
        <span className="fleet-context-mark" aria-hidden="true">
          OF
        </span>
        <div>
          <p className="eyebrow">{t("fleet.eyebrow")}</p>
          <h1 id="fleet-title">{t("fleet.title")}</h1>
          <p>{t("fleet.contextRequired")}</p>
          <Link className="primary-button" href="/organizations">
            {t("fleet.chooseOrganization")}
          </Link>
        </div>
      </section>
    );
  }

  const registerLinks = `?organization_id=${organizationId}`;

  return (
    <div className="fleet-workspace">
      <nav className="breadcrumbs" aria-label={t("nav.breadcrumbs")}>
        <Link href="/">{t("nav.home")}</Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{t("fleet.title")}</span>
      </nav>

      <header className="fleet-command-header">
        <div>
          <p className="eyebrow">{t("fleet.eyebrow")}</p>
          <h1>{t("fleet.title")}</h1>
          <p>{t("fleet.description")}</p>
        </div>
        <div className="fleet-header-actions">
          <Link
            className="button-secondary"
            href={`/fleet/assignments/new${registerLinks}` as Route}
          >
            {t("fleet.newAssignment")}
          </Link>
          <Link
            className="primary-button"
            href={`/fleet/vehicles/new${registerLinks}` as Route}
          >
            {t("fleet.registerVehicle")}
          </Link>
        </div>
      </header>

      <section className="fleet-metrics" aria-label={t("fleet.snapshot")}>
        {counts.map(([label, value, tone]) => (
          <article className={`fleet-metric metric-${tone}`} key={label}>
            <span>{label}</span>
            <strong>{value}</strong>
            <i aria-hidden="true" />
          </article>
        ))}
      </section>

      <section className="fleet-register-shell">
        <div className="fleet-register-heading">
          <div>
            <span className="step-label">{t("fleet.operationalRegister")}</span>
            <h2>{t(`fleet.${register}`)}</h2>
          </div>
          <div className="fleet-register-actions">
            {register === "drivers" && (
              <Link
                className="button-secondary"
                href={`/fleet/drivers/new${registerLinks}` as Route}
              >
                {t("fleet.registerDriver")}
              </Link>
            )}
            <span className="fleet-live-stamp">
              <i aria-hidden="true" />
              {t("fleet.serverAuthoritative")}
            </span>
          </div>
        </div>

        <div className="fleet-tabs" role="tablist">
          {(["vehicles", "drivers", "assignments"] as const).map((item) => (
            <button
              aria-selected={register === item}
              key={item}
              onClick={() => setRegister(item)}
              role="tab"
              type="button"
            >
              {t(`fleet.${item}`)}
              <span>
                {
                  {
                    vehicles: vehicles.length,
                    drivers: drivers.length,
                    assignments: assignments.length,
                  }[item]
                }
              </span>
            </button>
          ))}
        </div>

        <form
          className="fleet-filter-bar"
          onSubmit={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            const data = new FormData(event.currentTarget);
            setState("loading");
            setQuery(String(data.get("query") ?? "").trim());
            setStatus(String(data.get("status") ?? ""));
          }}
        >
          <label className="fleet-search">
            <span>{t("fleet.search")}</span>
            <input
              defaultValue={query}
              name="query"
              placeholder={t("fleet.searchPlaceholder")}
              type="search"
            />
          </label>
          <label>
            <span>{t("fleet.status")}</span>
            <select defaultValue={status} name="status">
              <option value="">{t("fleet.allStatuses")}</option>
              <option value="active">{t("fleet.statusActive")}</option>
              <option value="draft">{t("fleet.statusDraft")}</option>
              <option value="suspended">{t("fleet.statusSuspended")}</option>
              <option value="out_of_service">
                {t("fleet.statusOutOfService")}
              </option>
              <option value="retired">{t("fleet.statusRetired")}</option>
            </select>
          </label>
          <button type="submit">{t("fleet.applyFilters")}</button>
        </form>

        <div aria-busy={state === "loading"} aria-live="polite">
          {state === "loading" && <FleetSkeleton />}
          {state === "forbidden" && (
            <FleetState
              title={t("state.forbidden")}
              detail={t("fleet.forbiddenDetail")}
              tone="danger"
            />
          )}
          {state === "error" && (
            <FleetState
              title={t("state.unavailable")}
              detail={t("fleet.errorDetail")}
              tone="warning"
            />
          )}
          {state === "empty" && (
            <FleetState
              title={t("fleet.emptyTitle")}
              detail={t("fleet.emptyDetail")}
              tone="neutral"
            />
          )}
          {(state === "ready" || state === "empty") && (
            <>
              {register === "vehicles" && vehicles.length > 0 && (
                <VehicleRegister
                  locale={locale}
                  organizationId={organizationId}
                  vehicles={vehicles}
                />
              )}
              {register === "drivers" && drivers.length > 0 && (
                <DriverRegister
                  drivers={drivers}
                  locale={locale}
                  organizationId={organizationId}
                />
              )}
              {register === "assignments" && assignments.length > 0 && (
                <AssignmentRegister assignments={assignments} locale={locale} />
              )}
            </>
          )}
        </div>
      </section>
    </div>
  );
}

function VehicleRegister({
  vehicles,
  locale,
  organizationId,
}: {
  readonly vehicles: readonly VehicleSummary[];
  readonly locale: Locale;
  readonly organizationId: string;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <div className="asset-passport-list">
      {vehicles.map((vehicle) => (
        <article className="asset-passport" key={vehicle.id}>
          <div className="asset-identity">
            <span>{t("fleet.plate")}</span>
            <strong>{vehicle.plate_number ?? t("fleet.unassigned")}</strong>
            <small>{vehicle.asset_number}</small>
          </div>
          <div className="asset-description">
            <strong>
              {[
                vehicle.manufacturer?.name,
                vehicle.model?.name,
                vehicle.model_year,
              ]
                .filter(Boolean)
                .join(" · ") || t("fleet.vehicle")}
            </strong>
            <span>
              {vehicle.ownership_type} · {vehicle.current_odometer_km} km
            </span>
          </div>
          <span
            className={`semantic-badge badge-${statusTone(vehicle.status)}`}
          >
            {vehicle.status.replaceAll("_", " ")}
          </span>
          <Link
            aria-label={`${t("fleet.openVehicle")} ${vehicle.asset_number}`}
            href={
              `/fleet/vehicles/${vehicle.id}?organization_id=${organizationId}` as Route
            }
          >
            {t("fleet.openRecord")} <span aria-hidden="true">→</span>
          </Link>
        </article>
      ))}
    </div>
  );
}

function DriverRegister({
  drivers,
  locale,
  organizationId,
}: {
  readonly drivers: readonly DriverSummary[];
  readonly locale: Locale;
  readonly organizationId: string;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <div className="fleet-table-wrap">
      <table className="fleet-table">
        <thead>
          <tr>
            <th scope="col">{t("fleet.driver")}</th>
            <th scope="col">{t("fleet.licence")}</th>
            <th scope="col">{t("fleet.availability")}</th>
            <th scope="col">{t("fleet.status")}</th>
            <th scope="col">{t("fleet.actions")}</th>
          </tr>
        </thead>
        <tbody>
          {drivers.map((driver) => (
            <tr key={driver.id}>
              <th scope="row">
                <strong>{driver.full_name}</strong>
                <span>{driver.employee_number}</span>
              </th>
              <td>
                {driver.licences?.[0]
                  ? `${driver.licences[0].classes.map((item) => item.code).join(", ")} · ${driver.licences[0].expires_on}`
                  : t("fleet.notRecorded")}
              </td>
              <td>{driver.availability_status.replaceAll("_", " ")}</td>
              <td>
                <span
                  className={`semantic-badge badge-${statusTone(driver.status)}`}
                >
                  {driver.status.replaceAll("_", " ")}
                </span>
              </td>
              <td>
                <Link
                  aria-label={`${t("fleet.openDriver")} ${driver.full_name}`}
                  href={
                    `/fleet/drivers/${driver.id}?organization_id=${organizationId}` as Route
                  }
                >
                  {t("fleet.openRecord")} <span aria-hidden="true">→</span>
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function AssignmentRegister({
  assignments,
  locale,
}: {
  readonly assignments: readonly AssignmentSummary[];
  readonly locale: Locale;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <ol className="assignment-ledger">
      {assignments.map((assignment) => (
        <li key={assignment.id}>
          <span className="assignment-line" aria-hidden="true" />
          <div>
            <strong>
              {assignment.vehicle?.plate_number ??
                assignment.vehicle?.asset_number ??
                t("fleet.vehicle")}
              <span aria-hidden="true"> ↔ </span>
              {assignment.driver?.full_name ?? t("fleet.driver")}
            </strong>
            <span>
              {assignment.assignment_type} ·{" "}
              {new Intl.DateTimeFormat(locale).format(
                new Date(assignment.starts_at),
              )}
            </span>
          </div>
          <span
            className={`semantic-badge badge-${statusTone(assignment.status)}`}
          >
            {assignment.status}
          </span>
          {assignment.acknowledgement_required &&
            !assignment.acknowledged_at && (
              <small>{t("fleet.awaitingAcknowledgement")}</small>
            )}
        </li>
      ))}
    </ol>
  );
}

function FleetSkeleton() {
  return (
    <div className="fleet-skeleton" role="status">
      <span />
      <span />
      <span />
    </div>
  );
}

function FleetState({
  title,
  detail,
  tone,
}: {
  readonly title: string;
  readonly detail: string;
  readonly tone: "neutral" | "warning" | "danger";
}) {
  return (
    <div className={`fleet-state fleet-state-${tone}`} role="status">
      <span aria-hidden="true">i</span>
      <div>
        <strong>{title}</strong>
        <p>{detail}</p>
      </div>
    </div>
  );
}

function statusTone(status: string): "success" | "warning" | "neutral" {
  if (status === "active" || status === "available") return "success";
  if (["suspended", "out_of_service", "expired"].includes(status))
    return "warning";
  return "neutral";
}
