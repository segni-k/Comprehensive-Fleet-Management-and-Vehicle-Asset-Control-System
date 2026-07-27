"use client";

import { translate, type Locale } from "@oromia/localization";
import type { Route } from "next";
import Link from "next/link";
import { useEffect, useState } from "react";
import type {
  AssignmentSummary,
  DriverSummary,
  FleetDocumentSummary,
} from "@/fleet/types";
import { apiRequest } from "@/platform/api-client";
import { FleetDocumentsPanel } from "./FleetDocumentsPanel";

interface DriverProfileRecord extends DriverSummary {
  readonly organization_id: string;
  readonly employment_status: string;
  readonly hired_on: string | null;
  readonly terminated_on: string | null;
  readonly record_version: number;
  readonly assignments: readonly AssignmentSummary[];
}

interface DriverProfileEnvelope {
  readonly data: DriverProfileRecord;
  readonly history: {
    readonly statuses: readonly {
      readonly id: string;
      readonly to_status: string;
      readonly availability_status: string;
      readonly reason: string | null;
      readonly effective_at: string;
    }[];
    readonly qualifications: readonly {
      readonly id: string;
      readonly title: string;
      readonly expires_on: string | null;
      readonly status: string;
    }[];
    readonly restrictions: readonly {
      readonly id: string;
      readonly description: string;
      readonly starts_at: string;
      readonly ends_at: string | null;
      readonly status: string;
    }[];
    readonly documents: readonly FleetDocumentSummary[];
  };
}

export function DriverProfile({
  driverId,
  organizationId,
  locale,
}: {
  readonly driverId: string;
  readonly organizationId: string;
  readonly locale: Locale;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const [record, setRecord] = useState<DriverProfileEnvelope | null>(null);
  const [failed, setFailed] = useState(false);
  const [reloadVersion, setReloadVersion] = useState(0);

  useEffect(() => {
    let active = true;
    apiRequest<DriverProfileEnvelope>(`/drivers/${driverId}`)
      .then((response) => {
        if (active) setRecord(response);
      })
      .catch(() => {
        if (active) setFailed(true);
      });
    return () => {
      active = false;
    };
  }, [driverId, reloadVersion]);

  if (failed) {
    return (
      <section className="fleet-state fleet-state-warning">
        <span aria-hidden="true">i</span>
        <div>
          <h1>{t("state.unavailable")}</h1>
          <p>{t("fleet.errorDetail")}</p>
        </div>
      </section>
    );
  }
  if (!record)
    return <div className="skeleton-block">{t("state.loading")}</div>;

  const driver = record.data;
  const licence = driver.licences?.[0];
  return (
    <div className="vehicle-profile">
      <nav className="breadcrumbs" aria-label={t("nav.breadcrumbs")}>
        <Link href={`/fleet?organization_id=${organizationId}` as Route}>
          {t("fleet.title")}
        </Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{driver.employee_number}</span>
      </nav>
      <header className="vehicle-profile-hero driver-profile-hero">
        <div className="driver-monogram" aria-hidden="true">
          {driver.full_name
            .split(/\s+/)
            .slice(0, 2)
            .map((part) => part[0])
            .join("")
            .toUpperCase()}
        </div>
        <div>
          <p className="eyebrow">{t("fleet.driverPassport")}</p>
          <h1>{driver.full_name}</h1>
          <p>
            {driver.employee_number} · {driver.employment_status}
          </p>
        </div>
        <span className="semantic-badge badge-success">
          {driver.availability_status.replaceAll("_", " ")}
        </span>
      </header>

      <section className="vehicle-profile-grid">
        <article className="vehicle-facts">
          <div>
            <p className="eyebrow">{t("fleet.licence")}</p>
            <h2>{t("fleet.licenceReadiness")}</h2>
          </div>
          <dl>
            <div>
              <dt>{t("fleet.licenceClass")}</dt>
              <dd>
                {licence?.classes.map((item) => item.code).join(", ") ||
                  t("fleet.notRecorded")}
              </dd>
            </div>
            <div>
              <dt>{t("fleet.issuingAuthority")}</dt>
              <dd>{licence?.issuing_authority ?? t("fleet.notRecorded")}</dd>
            </div>
            <div>
              <dt>{t("fleet.licenceExpiry")}</dt>
              <dd>{licence?.expires_on ?? t("fleet.notRecorded")}</dd>
            </div>
            <div>
              <dt>{t("fleet.status")}</dt>
              <dd>{licence?.status ?? t("fleet.notRecorded")}</dd>
            </div>
          </dl>
        </article>
        <article className="vehicle-compliance-board">
          <div>
            <p className="eyebrow">{t("fleet.operationalControls")}</p>
            <h2>{t("fleet.driverReadiness")}</h2>
          </div>
          <ul>
            <li>
              <span>{t("fleet.qualifications")}</span>
              <strong>{record.history.qualifications.length}</strong>
            </li>
            <li>
              <span>{t("fleet.activeRestrictions")}</span>
              <strong>
                {
                  record.history.restrictions.filter(
                    (item) => item.status === "active",
                  ).length
                }
              </strong>
            </li>
            <li>
              <span>{t("fleet.assignmentHistory")}</span>
              <strong>{driver.assignments.length}</strong>
            </li>
          </ul>
        </article>
      </section>

      <section className="driver-evidence-grid">
        <article>
          <h2>{t("fleet.qualifications")}</h2>
          {record.history.qualifications.length ? (
            <ul>
              {record.history.qualifications.map((item) => (
                <li key={item.id}>
                  <strong>{item.title}</strong>
                  <span>
                    {item.status} · {item.expires_on ?? t("fleet.notRecorded")}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p>{t("fleet.notRecorded")}</p>
          )}
        </article>
        <article>
          <h2>{t("fleet.activeRestrictions")}</h2>
          {record.history.restrictions.length ? (
            <ul>
              {record.history.restrictions.map((item) => (
                <li key={item.id}>
                  <strong>{item.description}</strong>
                  <span>
                    {item.status} · {item.ends_at ?? t("fleet.notRecorded")}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p>{t("fleet.notRecorded")}</p>
          )}
        </article>
        <article>
          <h2>{t("fleet.assignmentHistory")}</h2>
          {driver.assignments.length ? (
            <ul>
              {driver.assignments.map((item) => (
                <li key={item.id}>
                  <strong>
                    {item.vehicle?.plate_number ??
                      item.vehicle?.asset_number ??
                      t("fleet.vehicle")}
                  </strong>
                  <span>
                    {item.assignment_type.replaceAll("_", " ")} · {item.status}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p>{t("fleet.notRecorded")}</p>
          )}
        </article>
      </section>

      <FleetDocumentsPanel
        documents={record.history.documents}
        locale={locale}
        onUploaded={() => setReloadVersion((value) => value + 1)}
        organizationId={organizationId}
        ownerId={driver.id}
        ownerType="driver"
      />

      <section className="vehicle-history">
        <div>
          <p className="eyebrow">{t("fleet.immutableHistory")}</p>
          <h2>{t("fleet.driverTimeline")}</h2>
        </div>
        <ol>
          {record.history.statuses.map((item) => (
            <li key={item.id}>
              <span aria-hidden="true" />
              <div>
                <strong>
                  {item.to_status.replaceAll("_", " ")} ·{" "}
                  {item.availability_status.replaceAll("_", " ")}
                </strong>
                <p>{item.reason ?? t("fleet.notRecorded")}</p>
              </div>
              <time dateTime={item.effective_at}>
                {new Intl.DateTimeFormat(locale, {
                  dateStyle: "medium",
                  timeStyle: "short",
                }).format(new Date(item.effective_at))}
              </time>
            </li>
          ))}
        </ol>
      </section>
    </div>
  );
}
