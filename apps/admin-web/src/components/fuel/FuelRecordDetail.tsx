"use client";

import { translate, type Locale } from "@oromia/localization";
import Link from "next/link";
import type { Route } from "next";
import { useEffect, useState, type FormEvent } from "react";
import type {
  FuelTransactionSummary,
  TripReconciliationSummary,
} from "@/fuel/types";
import { ApiProblem, apiRequest } from "@/platform/api-client";

export function FuelRecordDetail({
  id,
  kind,
  locale,
  organizationId,
}: {
  readonly id: string;
  readonly kind: "transaction" | "reconciliation";
  readonly locale: Locale;
  readonly organizationId?: string;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const [record, setRecord] = useState<
    FuelTransactionSummary | TripReconciliationSummary | null
  >(null);
  const [state, setState] = useState<
    "context" | "loading" | "ready" | "forbidden" | "not-found" | "error"
  >(organizationId ? "loading" : "context");
  const [feedback, setFeedback] = useState<"idle" | "saved" | "error">("idle");
  const [refresh, setRefresh] = useState(0);
  useEffect(() => {
    if (!organizationId) return;
    const path =
      kind === "transaction"
        ? `/fuel/transactions/${id}`
        : `/trip-reconciliations/${id}`;
    let active = true;
    void apiRequest<{
      data: FuelTransactionSummary | TripReconciliationSummary;
    }>(path)
      .then((result) => {
        if (active) {
          setRecord(result.data);
          setState("ready");
        }
      })
      .catch((error: unknown) => {
        if (!active) return;
        setState(
          error instanceof ApiProblem && error.problem.status === 403
            ? "forbidden"
            : error instanceof ApiProblem && error.problem.status === 404
              ? "not-found"
              : "error",
        );
      });
    return () => {
      active = false;
    };
  }, [id, kind, organizationId, refresh]);
  const act = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!record) return;
    const values = Object.fromEntries(new FormData(event.currentTarget));
    const path =
      kind === "transaction"
        ? `/fuel/transactions/${id}/actions`
        : `/trip-reconciliations/${id}/actions`;
    try {
      await apiRequest(path, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
        },
        body: JSON.stringify({
          ...values,
          record_version: record.record_version,
        }),
      });
      setFeedback("saved");
      setRefresh((value) => value + 1);
    } catch {
      setFeedback("error");
    }
  };
  if (!organizationId)
    return (
      <FuelDetailState
        title={t("fuel.organization")}
        detail={t("fuel.contextRequired")}
      />
    );
  if (state !== "ready" || !record)
    return (
      <FuelDetailState
        title={
          state === "forbidden"
            ? t("state.forbidden")
            : state === "not-found"
              ? t("state.notFound")
              : state === "loading"
                ? t("state.loading")
                : t("state.unavailable")
        }
        detail={
          state === "forbidden"
            ? t("fuel.forbiddenDetail")
            : t("fuel.errorDetail")
        }
      />
    );
  const transaction =
    kind === "transaction" ? (record as FuelTransactionSummary) : null;
  const reconciliation =
    kind === "reconciliation" ? (record as TripReconciliationSummary) : null;
  return (
    <div className="geo-workspace fuel-workspace">
      <nav className="breadcrumbs" aria-label={t("nav.breadcrumbs")}>
        <Link href="/">{t("nav.home")}</Link>
        <span>/</span>
        <Link href={`/fuel?organization_id=${organizationId}` as Route}>
          {t("nav.fuel")}
        </Link>
        <span>/</span>
        <span aria-current="page">
          {transaction?.transaction_number ?? reconciliation?.trip_id}
        </span>
      </nav>
      <header className="geo-command-header">
        <div>
          <p className="eyebrow">{t("fuel.detail")}</p>
          <h1>{transaction?.transaction_number ?? reconciliation?.trip_id}</h1>
          <p>{t("fuel.detailDescription")}</p>
        </div>
        <span className="semantic-badge badge-information">
          {record.status}
        </span>
      </header>
      <div className="trip-detail-grid">
        <section className="geo-register-shell">
          <h2>{t("fuel.evidence")}</h2>
          {transaction ? (
            <dl className="fuel-evidence-list">
              <div>
                <dt>{t("fuel.quantity")}</dt>
                <dd>{transaction.quantity_litres} L</dd>
              </div>
              <div>
                <dt>{t("fuel.amount")}</dt>
                <dd>{transaction.total_amount} ETB</dd>
              </div>
              <div>
                <dt>{t("fuel.receipt")}</dt>
                <dd>{transaction.receipt_number ?? "—"}</dd>
              </div>
            </dl>
          ) : (
            <dl className="fuel-evidence-list">
              <div>
                <dt>{t("fuel.calculatedDistance")}</dt>
                <dd>{reconciliation?.travelled_distance_km ?? "—"} km</dd>
              </div>
              <div>
                <dt>{t("fuel.expectedConsumption")}</dt>
                <dd>{reconciliation?.expected_fuel_litres ?? "—"} L</dd>
              </div>
              <div>
                <dt>{t("fuel.actualConsumption")}</dt>
                <dd>{reconciliation?.fuel_consumed_litres} L</dd>
              </div>
            </dl>
          )}
          <h3>{t("fuel.duplicateIndicators")}</h3>
          <ul>
            {transaction?.duplicate_indicators?.length ? (
              transaction.duplicate_indicators.map((item) => (
                <li key={item}>{item}</li>
              ))
            ) : (
              <li>{t("fuel.noDuplicates")}</li>
            )}
          </ul>
          <h3>{t("fuel.blockers")}</h3>
          <ul>
            {reconciliation?.blockers.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
          <h3>{t("fuel.warnings")}</h3>
          <ul>
            {reconciliation?.warnings.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
        </section>
        <aside className="geo-register-shell">
          <h2>{t("fuel.actions")}</h2>
          <form
            className="fuel-action-form"
            onSubmit={(event) => void act(event)}
          >
            <label>
              <span>{t("fuel.actions")}</span>
              <select name="action">
                {(kind === "transaction"
                  ? ["accept", "reject", "reverse"]
                  : ["submit", "approve", "reject", "reopen"]
                ).map((item) => (
                  <option key={item} value={item}>
                    {item}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span>{t("fuel.reason")}</span>
              <textarea name="reason" required minLength={5} maxLength={2000} />
            </label>
            <button type="submit">{t("fuel.confirmAction")}</button>
          </form>
          {feedback === "saved" && (
            <p role="status">{t("fuel.actionSucceeded")}</p>
          )}
          {feedback === "error" && <p role="alert">{t("fuel.actionFailed")}</p>}
          {reconciliation && (
            <>
              <h2>{t("fuel.history")}</h2>
              {reconciliation.history?.length ? (
                <ol className="trip-timeline">
                  {reconciliation.history.map((item) => (
                    <li key={item.id}>
                      <span />
                      <div>
                        <strong>{item.action}</strong>
                        <time>
                          {new Date(item.occurred_at).toLocaleString(locale)}
                        </time>
                      </div>
                    </li>
                  ))}
                </ol>
              ) : (
                <p>{t("fuel.noHistory")}</p>
              )}
            </>
          )}
        </aside>
      </div>
    </div>
  );
}
function FuelDetailState({
  title,
  detail,
}: {
  readonly title: string;
  readonly detail: string;
}) {
  return (
    <section className="geo-state geo-state-warning">
      <span>!</span>
      <div>
        <h1>{title}</h1>
        <p>{detail}</p>
      </div>
    </section>
  );
}
