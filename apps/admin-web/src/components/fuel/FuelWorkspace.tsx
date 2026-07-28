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
import type {
  FuelReferenceData,
  FuelRequestSummary,
  FuelTransactionSummary,
  Paged,
  TripReconciliationSummary,
} from "@/fuel/types";
import { ApiProblem, apiRequest } from "@/platform/api-client";

type Register = "requests" | "transactions" | "reconciliations" | "references";
type LoadState =
  | "context"
  | "loading"
  | "ready"
  | "empty"
  | "forbidden"
  | "error";
interface OrganizationOption {
  readonly id: string;
  readonly code: string;
  readonly name: Readonly<Record<string, string>>;
}

export function FuelWorkspace({
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
  const [organizations, setOrganizations] = useState<
    readonly OrganizationOption[]
  >([]);
  const [register, setRegister] = useState<Register>("transactions");
  const [state, setState] = useState<LoadState>(
    organizationId ? "loading" : "context",
  );
  const [requests, setRequests] = useState<readonly FuelRequestSummary[]>([]);
  const [transactions, setTransactions] = useState<
    readonly FuelTransactionSummary[]
  >([]);
  const [reconciliations, setReconciliations] = useState<
    readonly TripReconciliationSummary[]
  >([]);
  const [references, setReferences] = useState<FuelReferenceData>({
    fuel_types: [],
    stations: [],
    price_references: [],
    vehicle_profiles: [],
  });
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const [refresh, setRefresh] = useState(0);
  const [showRequest, setShowRequest] = useState(false);
  const [feedback, setFeedback] = useState<
    "idle" | "saving" | "saved" | "error"
  >("idle");

  useEffect(() => {
    let active = true;
    void apiRequest<{ data: readonly OrganizationOption[] }>(
      "/organizations?filter%5Bstatus%5D=active&page_size=100",
    )
      .then((result) => {
        if (active) setOrganizations(result.data);
      })
      .catch(() => {
        if (active) setOrganizations([]);
      });
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (!organizationId) return;
    let active = true;
    const query = new URLSearchParams({
      organization_id: organizationId,
      page_size: "100",
    });
    if (status) query.set("status", status);
    if (search) query.set("search", search);
    void Promise.all([
      apiRequest<Paged<FuelRequestSummary>>(`/fuel/requests?${query}`),
      apiRequest<Paged<FuelTransactionSummary>>(`/fuel/transactions?${query}`),
      apiRequest<Paged<TripReconciliationSummary>>(
        `/trip-reconciliations?${query}`,
      ),
      apiRequest<{ data: FuelReferenceData }>(
        `/fuel/reference-data?organization_id=${organizationId}`,
      ),
    ])
      .then(
        ([requestData, transactionData, reconciliationData, referenceData]) => {
          if (!active) return;
          setRequests(requestData.data);
          setTransactions(transactionData.data);
          setReconciliations(reconciliationData.data);
          setReferences(referenceData.data);
          setState(
            requestData.total +
              transactionData.total +
              reconciliationData.total ===
              0
              ? "empty"
              : "ready",
          );
        },
      )
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
  }, [organizationId, refresh, search, status]);

  const metrics = useMemo(
    () =>
      [
        [t("fuel.metricRequests"), requests.length, "primary"],
        [t("fuel.metricTransactions"), transactions.length, "information"],
        [
          t("fuel.metricReview"),
          transactions.filter((item) => item.status === "review_required")
            .length,
          "warning",
        ],
        [t("fuel.metricReconciliations"), reconciliations.length, "success"],
      ] as const,
    [reconciliations, requests, t, transactions],
  );

  const createRequest = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!organizationId) return;
    setFeedback("saving");
    const data = Object.fromEntries(new FormData(event.currentTarget));
    try {
      await apiRequest("/fuel/requests", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
        },
        body: JSON.stringify({
          ...data,
          organization_id: organizationId,
          requested_litres: String(data.requested_litres),
        }),
      });
      setFeedback("saved");
      setShowRequest(false);
      setRefresh((value) => value + 1);
    } catch {
      setFeedback("error");
    }
  };

  return (
    <div className="geo-workspace fuel-workspace">
      <nav className="breadcrumbs" aria-label={t("nav.breadcrumbs")}>
        <Link href="/">{t("nav.home")}</Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{t("nav.fuel")}</span>
      </nav>
      <header className="geo-command-header">
        <div>
          <p className="eyebrow">{t("fuel.eyebrow")}</p>
          <h1>{t("fuel.title")}</h1>
          <p>{t("fuel.description")}</p>
        </div>
        <label className="trip-organization-selector">
          <span>{t("fuel.organization")}</span>
          <select
            value={organizationId ?? ""}
            onChange={(event) =>
              router.push(
                (event.target.value
                  ? `/fuel?organization_id=${event.target.value}`
                  : "/fuel") as Route,
              )
            }
          >
            <option value="">{t("fuel.chooseOrganization")}</option>
            {organizations.map((organization) => (
              <option key={organization.id} value={organization.id}>
                {organization.code} —{" "}
                {organization.name[locale] ?? organization.name.en}
              </option>
            ))}
          </select>
          <small>{t("fuel.serverAuthoritative")}</small>
        </label>
      </header>
      {!organizationId ? (
        <section className="geo-context-state">
          <span aria-hidden="true">FM</span>
          <div>
            <h2>{t("fuel.organization")}</h2>
            <p>{t("fuel.contextRequired")}</p>
          </div>
        </section>
      ) : (
        <>
          <section className="geo-metrics" aria-label={t("fuel.snapshot")}>
            {metrics.map(([label, value, tone]) => (
              <article className={`geo-metric geo-${tone}`} key={label}>
                <span>{label}</span>
                <strong>{value}</strong>
                <i aria-hidden="true" />
              </article>
            ))}
          </section>
          <section className="geo-register-shell">
            <div className="geo-register-heading">
              <div>
                <span className="step-label">
                  {t("fuel.serverAuthoritative")}
                </span>
                <h2>{t(`fuel.${register}`)}</h2>
              </div>
              <button
                className="primary-button"
                onClick={() => setShowRequest((value) => !value)}
                type="button"
              >
                {t("fuel.newRequest")}
              </button>
            </div>
            {showRequest && (
              <form
                className="fuel-request-form"
                onSubmit={(event) => void createRequest(event)}
              >
                <label>
                  <span>{t("fuel.tripId")}</span>
                  <input
                    name="trip_id"
                    required
                    minLength={26}
                    maxLength={26}
                  />
                </label>
                <label>
                  <span>{t("fuel.vehicleId")}</span>
                  <input
                    name="vehicle_id"
                    required
                    minLength={26}
                    maxLength={26}
                  />
                </label>
                <label>
                  <span>{t("fuel.driverId")}</span>
                  <input
                    name="driver_id"
                    required
                    minLength={26}
                    maxLength={26}
                  />
                </label>
                <label>
                  <span>{t("fuel.fuelType")}</span>
                  <select name="fuel_type_id" required>
                    <option value="">{t("fuel.fuelType")}</option>
                    {references.fuel_types.map((type) => (
                      <option key={type.id} value={type.id}>
                        {type.name[locale] ?? type.name.en ?? type.code}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  <span>{t("fuel.requestedLitres")}</span>
                  <input
                    name="requested_litres"
                    required
                    type="number"
                    min="0.001"
                    step="0.001"
                  />
                </label>
                <label>
                  <span>{t("fuel.fundingReference")}</span>
                  <input name="funding_reference" maxLength={120} />
                </label>
                <label className="full-field">
                  <span>{t("fuel.justification")}</span>
                  <textarea name="justification" maxLength={2000} />
                </label>
                <button disabled={feedback === "saving"} type="submit">
                  {t("fuel.createRequest")}
                </button>
              </form>
            )}
            {feedback === "saved" && (
              <p className="form-success" role="status">
                {t("fuel.actionSucceeded")}
              </p>
            )}
            {feedback === "error" && (
              <p className="form-error" role="alert">
                {t("fuel.actionFailed")}
              </p>
            )}
            <div className="geo-tabs fuel-tabs" role="tablist">
              {(
                [
                  "transactions",
                  "requests",
                  "reconciliations",
                  "references",
                ] as const
              ).map((item) => (
                <button
                  aria-selected={register === item}
                  key={item}
                  onClick={() => setRegister(item)}
                  role="tab"
                  type="button"
                >
                  {t(`fuel.${item}`)}
                </button>
              ))}
            </div>
            {register !== "references" && (
              <form
                className="geo-filter-bar"
                onSubmit={(event) => {
                  event.preventDefault();
                  const data = new FormData(event.currentTarget);
                  setState("loading");
                  setSearch(String(data.get("search") ?? ""));
                  setStatus(String(data.get("status") ?? ""));
                }}
              >
                <label>
                  <span>{t("fuel.search")}</span>
                  <input name="search" type="search" />
                </label>
                <label>
                  <span>{t("fuel.status")}</span>
                  <select name="status">
                    <option value="">{t("fuel.allStatuses")}</option>
                    {[
                      "submitted",
                      "review_required",
                      "accepted",
                      "rejected",
                      "reversed",
                      "draft",
                      "approved",
                      "reopened",
                    ].map((item) => (
                      <option key={item} value={item}>
                        {item.replaceAll("_", " ")}
                      </option>
                    ))}
                  </select>
                </label>
                <button type="submit">{t("fuel.applyFilters")}</button>
              </form>
            )}
            <div aria-busy={state === "loading"} aria-live="polite">
              {state === "loading" && (
                <div
                  className="geo-skeleton"
                  role="status"
                  aria-label={t("state.loading")}
                >
                  <span />
                  <span />
                  <span />
                </div>
              )}
              {state === "forbidden" && (
                <FuelState
                  title={t("state.forbidden")}
                  detail={t("fuel.forbiddenDetail")}
                />
              )}
              {state === "error" && (
                <FuelState
                  title={t("state.unavailable")}
                  detail={t("fuel.errorDetail")}
                >
                  <button
                    onClick={() => setRefresh((value) => value + 1)}
                    type="button"
                  >
                    {t("fuel.retry")}
                  </button>
                </FuelState>
              )}
              {(state === "ready" || state === "empty") &&
                register === "transactions" && (
                  <TransactionRegister
                    items={transactions}
                    locale={locale}
                    organizationId={organizationId}
                  />
                )}
              {(state === "ready" || state === "empty") &&
                register === "requests" && (
                  <RequestRegister items={requests} locale={locale} />
                )}
              {(state === "ready" || state === "empty") &&
                register === "reconciliations" && (
                  <ReconciliationRegister
                    items={reconciliations}
                    locale={locale}
                    organizationId={organizationId}
                  />
                )}
              {(state === "ready" || state === "empty") &&
                register === "references" && (
                  <ReferenceRegister data={references} locale={locale} />
                )}
            </div>
          </section>
        </>
      )}
    </div>
  );
}

function TransactionRegister({
  items,
  locale,
  organizationId,
}: {
  readonly items: readonly FuelTransactionSummary[];
  readonly locale: Locale;
  readonly organizationId: string;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  if (!items.length)
    return (
      <FuelState title={t("fuel.emptyTitle")} detail={t("fuel.emptyDetail")} />
    );
  return (
    <div className="table-scroll fuel-register" role="region" tabIndex={0}>
      <table>
        <thead>
          <tr>
            <th>{t("fuel.transactionNumber")}</th>
            <th>{t("fuel.trip")}</th>
            <th>{t("fuel.quantity")}</th>
            <th>{t("fuel.amount")}</th>
            <th>{t("fuel.receipt")}</th>
            <th>{t("fuel.status")}</th>
            <th>{t("fuel.actions")}</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.id}>
              <td>{item.transaction_number}</td>
              <td>{item.trip_id ?? "—"}</td>
              <td>{item.quantity_litres} L</td>
              <td>{item.total_amount} ETB</td>
              <td>{item.receipt_number ?? "—"}</td>
              <td>
                <span className="semantic-badge badge-information">
                  {item.status.replaceAll("_", " ")}
                </span>
              </td>
              <td>
                <Link
                  href={
                    `/fuel/transactions/${item.id}?organization_id=${organizationId}` as Route
                  }
                >
                  {t("fuel.openRecord")} →
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
function RequestRegister({
  items,
  locale,
}: {
  readonly items: readonly FuelRequestSummary[];
  readonly locale: Locale;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  if (!items.length)
    return (
      <FuelState title={t("fuel.emptyTitle")} detail={t("fuel.emptyDetail")} />
    );
  return (
    <div className="fuel-card-list">
      {items.map((item) => (
        <article key={item.id}>
          <div>
            <span>{t("fuel.requestNumber")}</span>
            <strong>{item.request_number}</strong>
          </div>
          <dl>
            <div>
              <dt>{t("fuel.quantity")}</dt>
              <dd>{item.requested_litres} L</dd>
            </div>
            <div>
              <dt>{t("fuel.recommended")}</dt>
              <dd>{item.recommended_litres} L</dd>
            </div>
          </dl>
          <span className="semantic-badge badge-warning">{item.status}</span>
        </article>
      ))}
    </div>
  );
}
function ReconciliationRegister({
  items,
  locale,
  organizationId,
}: {
  readonly items: readonly TripReconciliationSummary[];
  readonly locale: Locale;
  readonly organizationId: string;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  if (!items.length)
    return (
      <FuelState title={t("fuel.emptyTitle")} detail={t("fuel.emptyDetail")} />
    );
  return (
    <div className="fuel-card-list">
      {items.map((item) => (
        <article key={item.id}>
          <div>
            <span>{t("fuel.trip")}</span>
            <strong>{item.trip_id}</strong>
          </div>
          <dl>
            <div>
              <dt>{t("fuel.calculatedDistance")}</dt>
              <dd>{item.travelled_distance_km ?? "—"} km</dd>
            </div>
            <div>
              <dt>{t("fuel.variance")}</dt>
              <dd>{item.fuel_variance_percent ?? "—"}%</dd>
            </div>
          </dl>
          <span
            className={`semantic-badge badge-${item.blockers.length ? "danger" : item.warnings.length ? "warning" : "success"}`}
          >
            {item.status}
          </span>
          <Link
            href={
              `/fuel/reconciliations/${item.id}?organization_id=${organizationId}` as Route
            }
          >
            {t("fuel.review")} →
          </Link>
        </article>
      ))}
    </div>
  );
}
function ReferenceRegister({
  data,
  locale,
}: {
  readonly data: FuelReferenceData;
  readonly locale: Locale;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <div className="fuel-reference-grid">
      <article>
        <h3>{t("fuel.referenceTypes")}</h3>
        <strong>{data.fuel_types.length}</strong>
      </article>
      <article>
        <h3>{t("fuel.referenceStations")}</h3>
        <strong>{data.stations.length}</strong>
      </article>
      <article>
        <h3>{t("fuel.referencePrices")}</h3>
        <strong>{data.price_references.length}</strong>
      </article>
      <article>
        <h3>{t("fuel.referenceProfiles")}</h3>
        <strong>{data.vehicle_profiles.length}</strong>
      </article>
    </div>
  );
}
function FuelState({
  title,
  detail,
  children,
}: {
  readonly title: string;
  readonly detail: string;
  readonly children?: React.ReactNode;
}) {
  return (
    <section className="geo-state geo-state-warning">
      <span aria-hidden="true">!</span>
      <div>
        <h3>{title}</h3>
        <p>{detail}</p>
        {children}
      </div>
    </section>
  );
}
