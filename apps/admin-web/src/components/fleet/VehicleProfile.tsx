"use client";

import { translate, type Locale } from "@oromia/localization";
import type { Route } from "next";
import Link from "next/link";
import { useEffect, useState } from "react";
import { FleetDocumentsPanel } from "./FleetDocumentsPanel";
import type { FleetDocumentSummary, VehicleSummary } from "@/fleet/types";
import { apiRequest } from "@/platform/api-client";

interface VehicleProfileRecord extends VehicleSummary {
  readonly vin: string | null;
  readonly chassis_number: string;
  readonly engine_number: string | null;
  readonly registration_number: string | null;
  readonly fuel_type: string;
  readonly transmission: string;
  readonly seating_capacity: number | null;
  readonly baseline_odometer_km: string;
  readonly commissioned_on: string | null;
}

interface VehicleProfileEnvelope {
  readonly data: VehicleProfileRecord;
  readonly history: {
    readonly statuses: readonly {
      readonly id: string;
      readonly to_status: string;
      readonly reason: string | null;
      readonly effective_at: string;
    }[];
    readonly plates: readonly {
      readonly id: string;
      readonly plate_number: string;
      readonly status: string;
      readonly effective_from: string;
    }[];
    readonly compliance: readonly {
      readonly id: string;
      readonly document_type: string;
      readonly expires_on: string | null;
      readonly status: string;
    }[];
    readonly documents: readonly FleetDocumentSummary[];
  };
}

export function VehicleProfile({
  vehicleId,
  organizationId,
  locale,
}: {
  readonly vehicleId: string;
  readonly organizationId: string;
  readonly locale: Locale;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const [record, setRecord] = useState<VehicleProfileEnvelope | null>(null);
  const [failed, setFailed] = useState(false);
  const [reloadVersion, setReloadVersion] = useState(0);

  useEffect(() => {
    let active = true;
    apiRequest<VehicleProfileEnvelope>(`/vehicles/${vehicleId}`)
      .then((response) => {
        if (active) setRecord(response);
      })
      .catch(() => {
        if (active) setFailed(true);
      });
    return () => {
      active = false;
    };
  }, [reloadVersion, vehicleId]);

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
  if (!record) {
    return <div className="skeleton-block">{t("state.loading")}</div>;
  }
  const vehicle = record.data;
  const facts = [
    [t("fleet.vin"), vehicle.vin],
    [t("fleet.chassisNumber"), vehicle.chassis_number],
    [t("fleet.engineNumber"), vehicle.engine_number],
    [t("fleet.registrationNumber"), vehicle.registration_number],
    [t("fleet.fuelType"), vehicle.fuel_type],
    [t("fleet.transmission"), vehicle.transmission],
    [t("fleet.seatingCapacity"), vehicle.seating_capacity],
    [t("fleet.baselineOdometer"), `${vehicle.baseline_odometer_km} km`],
  ];

  return (
    <div className="vehicle-profile">
      <nav className="breadcrumbs" aria-label={t("nav.breadcrumbs")}>
        <Link href={`/fleet?organization_id=${organizationId}` as Route}>
          {t("fleet.title")}
        </Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{vehicle.asset_number}</span>
      </nav>
      <header className="vehicle-profile-hero">
        <div className="vehicle-plate-block">
          <span>{t("fleet.plate")}</span>
          <strong>{vehicle.plate_number ?? t("fleet.unassigned")}</strong>
          <small>{vehicle.asset_number}</small>
        </div>
        <div>
          <p className="eyebrow">{t("fleet.assetPassport")}</p>
          <h1>
            {[
              vehicle.manufacturer?.name,
              vehicle.model?.name,
              vehicle.model_year,
            ]
              .filter(Boolean)
              .join(" · ")}
          </h1>
          <p>{t("fleet.assetPassportDetail")}</p>
        </div>
        <span className="semantic-badge badge-success">
          {vehicle.status.replaceAll("_", " ")}
        </span>
      </header>
      <section className="vehicle-profile-grid">
        <article className="vehicle-facts">
          <div>
            <p className="eyebrow">{t("fleet.vehicleIdentity")}</p>
            <h2>{t("fleet.authoritativeFacts")}</h2>
          </div>
          <dl>
            {facts.map(([label, value]) => (
              <div key={String(label)}>
                <dt>{label}</dt>
                <dd>{value ?? t("fleet.notRecorded")}</dd>
              </div>
            ))}
          </dl>
        </article>
        <article className="vehicle-compliance-board">
          <div>
            <p className="eyebrow">{t("fleet.complianceSection")}</p>
            <h2>{t("fleet.documentReadiness")}</h2>
          </div>
          <ul>
            {record.history.compliance.map((item) => (
              <li key={item.id}>
                <span>{item.document_type.replaceAll("_", " ")}</span>
                <strong>{item.expires_on ?? t("fleet.notRecorded")}</strong>
                <i
                  className={`badge-${item.status === "current" ? "success" : "neutral"}`}
                >
                  {item.status}
                </i>
              </li>
            ))}
          </ul>
        </article>
      </section>
      <FleetDocumentsPanel
        documents={record.history.documents}
        locale={locale}
        onUploaded={() => setReloadVersion((value) => value + 1)}
        organizationId={organizationId}
        ownerId={vehicle.id}
        ownerType="vehicle"
      />
      <section className="vehicle-history">
        <div>
          <p className="eyebrow">{t("fleet.immutableHistory")}</p>
          <h2>{t("fleet.lifecycleTimeline")}</h2>
        </div>
        <ol>
          {record.history.statuses.map((item) => (
            <li key={item.id}>
              <span aria-hidden="true" />
              <div>
                <strong>{item.to_status.replaceAll("_", " ")}</strong>
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
