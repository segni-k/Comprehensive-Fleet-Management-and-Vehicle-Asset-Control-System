"use client";

import { translate, type Locale } from "@oromia/localization";
import { useState } from "react";

type View = "inbox" | "audit" | "documents" | "delivery";
type Tone = "neutral" | "review" | "success" | "warning";
type RegisterRecord = readonly [
  title: string,
  detail: string,
  state: string,
  tone: Tone,
];

export function OperationsControlRoom({
  locale = "en",
}: {
  readonly locale?: Locale;
}) {
  const [view, setView] = useState<View>("inbox");
  const [query, setQuery] = useState("");
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const views: ReadonlyArray<{ id: View; label: string; count: string }> = [
    { id: "inbox", label: t("operations.approvals"), count: "4" },
    { id: "audit", label: t("operations.auditEvidence"), count: "128" },
    { id: "documents", label: t("operations.documentTrust"), count: "7" },
    {
      id: "delivery",
      label: t("operations.deliveryOperations"),
      count: "2",
    },
  ];
  const records: Record<View, readonly RegisterRecord[]> = {
    inbox: [
      [
        t("operations.neutralConfigurationReview"),
        t("operations.independentDecision"),
        t("operations.dueIn42Minutes"),
        "review",
      ],
      [
        t("operations.accessCertificationCycle"),
        t("operations.authoritiesSampled"),
        t("operations.dueTomorrow"),
        "warning",
      ],
      [
        t("operations.documentVerification"),
        t("operations.scanReceived"),
        t("operations.ready"),
        "success",
      ],
    ],
    audit: [
      [
        "workflow.approve.succeeded",
        t("operations.workflowLabel"),
        t("operations.chainVerified"),
        "success",
      ],
      [
        "document.download.succeeded",
        t("operations.documentLabel"),
        t("operations.authorizedAccess"),
        "neutral",
      ],
      [
        "outbox.replay.requested",
        t("operations.operationsLabel"),
        t("operations.highPriorityReview"),
        "warning",
      ],
    ],
    documents: [
      [
        t("operations.policyEvidence"),
        "application/pdf · 842 KB",
        t("operations.trusted"),
        "success",
      ],
      [
        t("operations.supportingMemorandum"),
        "application/pdf · 326 KB",
        t("operations.scanning"),
        "review",
      ],
      [
        t("operations.legacyAttachment"),
        "image/jpeg · 1.2 MB",
        t("operations.superseded"),
        "neutral",
      ],
    ],
    delivery: [
      [
        "workflow.transition.completed",
        t("operations.publishedAttempt"),
        t("operations.delivered"),
        "success",
      ],
      [
        "notification.created",
        t("operations.retryScheduled"),
        t("operations.retryable"),
        "warning",
      ],
      [
        "documents.scan.requested",
        t("operations.workerLock"),
        t("operations.processing"),
        "review",
      ],
    ],
  };
  const visible = records[view].filter((record) =>
    record.join(" ").toLowerCase().includes(query.toLowerCase()),
  );

  return (
    <div className="control-room">
      <header className="operations-header">
        <div>
          <span className="eyebrow">{t("operations.assurance")}</span>
          <h1>{t("operations.evidenceTitle")}</h1>
          <p>{t("operations.evidenceDescription")}</p>
        </div>
        <div
          className="assurance-seal"
          aria-label={t("operations.auditIntegrityStatus")}
        >
          <span aria-hidden="true">✓</span>
          <div>
            <strong>{t("operations.integrityVerified")}</strong>
            <small>{t("operations.integrityDetail")}</small>
          </div>
        </div>
      </header>

      <section className="operations-pulse" aria-label={t("operations.status")}>
        <div>
          <strong>4</strong>
          <span>{t("operations.decisionsDue")}</span>
        </div>
        <div>
          <strong>7</strong>
          <span>{t("operations.documentsInTrust")}</span>
        </div>
        <div>
          <strong>99.8%</strong>
          <span>{t("operations.firstAttemptDelivery")}</span>
        </div>
        <div>
          <strong>2</strong>
          <span>{t("operations.retriesControlled")}</span>
        </div>
      </section>

      <div className="operations-layout">
        <nav className="control-spine" aria-label={t("operations.views")}>
          {views.map((item, index) => (
            <button
              aria-current={view === item.id ? "page" : undefined}
              key={item.id}
              onClick={() => setView(item.id)}
              type="button"
            >
              <span className="spine-index">
                {String(index + 1).padStart(2, "0")}
              </span>
              <span>{item.label}</span>
              <strong>{item.count}</strong>
            </button>
          ))}
        </nav>

        <section className="operations-workbench" aria-live="polite">
          <div className="workbench-toolbar">
            <div>
              <span className="step-label">
                {t("operations.currentRegister")}
              </span>
              <h2>{views.find((item) => item.id === view)?.label}</h2>
            </div>
            <label className="operations-search">
              <span>{t("operations.searchRegister")}</span>
              <input
                onChange={(event) => setQuery(event.target.value)}
                placeholder={t("operations.searchPlaceholder")}
                type="search"
                value={query}
              />
            </label>
          </div>

          <div className="responsive-register" role="list">
            {visible.length > 0 ? (
              visible.map(([title, detail, state, tone]) => (
                <article className="register-row" key={title} role="listitem">
                  <span
                    className={`state-marker state-${tone}`}
                    aria-hidden="true"
                  />
                  <div>
                    <strong>{title}</strong>
                    <span>{detail}</span>
                  </div>
                  <span className={`semantic-badge badge-${tone}`}>
                    {state}
                  </span>
                  <button
                    aria-label={`${t("operations.openRecord")}: ${title}`}
                    className="row-action"
                    type="button"
                  >
                    {t("operations.review")} <span aria-hidden="true">→</span>
                  </button>
                </article>
              ))
            ) : (
              <div className="filtered-empty" role="status">
                <strong>{t("operations.noMatching")}</strong>
                <span>{t("operations.adjustSearch")}</span>
                <button onClick={() => setQuery("")} type="button">
                  {t("operations.clearSearch")}
                </button>
              </div>
            )}
          </div>
        </section>
      </div>

      <aside className="delivery-safeguard">
        <span aria-hidden="true">i</span>
        <div>
          <strong>{t("operations.deliveryGuarantee")}</strong>
          <p>{t("operations.deliveryGuaranteeDetail")}</p>
        </div>
        <button type="button">{t("operations.inspectOutbox")}</button>
      </aside>
    </div>
  );
}
