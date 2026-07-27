"use client";

import { translate, type Locale } from "@oromia/localization";
import type { Route } from "next";
import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";
import type {
  ApiEnvelope,
  DriverSummary,
  FleetReferenceData,
  PagedEnvelope,
  ReferenceRecord,
  VehicleSummary,
} from "@/fleet/types";
import { ApiProblem, apiRequest } from "@/platform/api-client";

type FormMode = "vehicle" | "driver" | "assignment";

export function FleetRecordForm({
  locale,
  organizationId,
  mode,
}: {
  readonly locale: Locale;
  readonly organizationId?: string;
  readonly mode: FormMode;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  const [references, setReferences] = useState<FleetReferenceData | null>(null);
  const [vehicles, setVehicles] = useState<readonly VehicleSummary[]>([]);
  const [drivers, setDrivers] = useState<readonly DriverSummary[]>([]);
  const [state, setState] = useState<
    "context" | "loading" | "ready" | "saving" | "saved" | "error"
  >(organizationId ? "loading" : "context");
  const [message, setMessage] = useState("");

  useEffect(() => {
    if (!organizationId) return;
    const query = `organization_id=${organizationId}`;
    let active = true;
    const load =
      mode === "assignment"
        ? Promise.all([
            apiRequest<ApiEnvelope<FleetReferenceData>>(
              `/fleet/reference-data?${query}`,
            ),
            apiRequest<PagedEnvelope<VehicleSummary>>(
              `/vehicles?${query}&status=active`,
            ),
            apiRequest<PagedEnvelope<DriverSummary>>(
              `/drivers?${query}&status=active`,
            ),
          ])
        : Promise.all([
            apiRequest<ApiEnvelope<FleetReferenceData>>(
              `/fleet/reference-data?${query}`,
            ),
            Promise.resolve(undefined),
            Promise.resolve(undefined),
          ]);
    load
      .then(([referenceData, vehicleData, driverData]) => {
        if (!active) return;
        setReferences(referenceData.data);
        setVehicles(vehicleData?.data ?? []);
        setDrivers(driverData?.data ?? []);
        setState("ready");
      })
      .catch(() => {
        if (active) setState("error");
      });
    return () => {
      active = false;
    };
  }, [mode, organizationId]);

  if (!organizationId) {
    return (
      <FormState
        detail={t("fleet.contextRequired")}
        title={t("fleet.chooseOrganization")}
      />
    );
  }
  if (state === "loading") {
    return (
      <FormState
        detail={t("fleet.formLoadingDetail")}
        title={t("state.loading")}
      />
    );
  }
  if (state === "error" || !references) {
    return (
      <FormState
        detail={t("fleet.errorDetail")}
        title={t("state.unavailable")}
      />
    );
  }
  if (state === "saved") {
    return (
      <section className="fleet-form-success" role="status">
        <span aria-hidden="true">✓</span>
        <div>
          <p className="eyebrow">{t("state.saved")}</p>
          <h1>{t(`fleet.formSaved.${mode}`)}</h1>
          <p>{t("fleet.formSavedDetail")}</p>
          <Link
            className="primary-button"
            href={`/fleet?organization_id=${organizationId}` as Route}
          >
            {t("fleet.returnToRegister")}
          </Link>
        </div>
      </section>
    );
  }
  const activeOrganizationId = organizationId;

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setState("saving");
    setMessage("");
    const request = buildRequest(mode, form, activeOrganizationId);
    try {
      await apiRequest(request.path, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
        },
        body: JSON.stringify(request.body),
      });
      setState("saved");
    } catch (error) {
      setState("ready");
      setMessage(
        error instanceof ApiProblem
          ? error.problem.title
          : t("state.unavailable"),
      );
    }
  }

  return (
    <div className="fleet-form-page">
      <nav className="breadcrumbs" aria-label={t("nav.breadcrumbs")}>
        <Link href={`/fleet?organization_id=${organizationId}` as Route}>
          {t("fleet.title")}
        </Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{t(`fleet.formTitle.${mode}`)}</span>
      </nav>
      <header className="fleet-form-header">
        <div>
          <p className="eyebrow">{t("fleet.controlledEntry")}</p>
          <h1>{t(`fleet.formTitle.${mode}`)}</h1>
          <p>{t(`fleet.formDescription.${mode}`)}</p>
        </div>
        <div className="fleet-form-assurance">
          <strong>{t("fleet.validatedCommand")}</strong>
          <span>{t("fleet.validatedCommandDetail")}</span>
        </div>
      </header>
      <form className="fleet-record-form" onSubmit={submit}>
        {mode === "vehicle" && (
          <VehicleFields locale={locale} references={references} />
        )}
        {mode === "driver" && (
          <DriverFields locale={locale} references={references} />
        )}
        {mode === "assignment" && (
          <AssignmentFields
            drivers={drivers}
            locale={locale}
            vehicles={vehicles}
          />
        )}
        {message && (
          <p className="form-error" role="alert">
            {message}
          </p>
        )}
        <footer className="fleet-form-footer">
          <Link
            className="button-secondary"
            href={`/fleet?organization_id=${organizationId}` as Route}
          >
            {t("action.cancel")}
          </Link>
          <button disabled={state === "saving"} type="submit">
            {state === "saving" ? t("fleet.saving") : t("fleet.submitRecord")}
          </button>
        </footer>
      </form>
    </div>
  );
}

function VehicleFields({
  locale,
  references,
}: {
  readonly locale: Locale;
  readonly references: FleetReferenceData;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <>
      <FormSection
        detail={t("fleet.identitySectionDetail")}
        title={t("fleet.identitySection")}
      >
        <Field label={t("fleet.assetNumber")} name="asset_number" required />
        <Field label={t("fleet.plate")} name="plate_number" required />
        <Field label={t("fleet.vin")} maxLength={17} name="vin" />
        <Field
          label={t("fleet.chassisNumber")}
          name="chassis_number"
          required
        />
        <Field label={t("fleet.engineNumber")} name="engine_number" />
        <Field
          label={t("fleet.registrationNumber")}
          name="registration_number"
        />
      </FormSection>
      <FormSection
        detail={t("fleet.classificationSectionDetail")}
        title={t("fleet.classificationSection")}
      >
        <SelectField
          label={t("fleet.category")}
          name="vehicle_category_id"
          options={references.categories}
          required
        />
        <SelectField
          label={t("fleet.class")}
          name="vehicle_class_id"
          options={references.classes}
          required
        />
        <SelectField
          label={t("fleet.manufacturer")}
          name="manufacturer_id"
          options={references.manufacturers}
          required
        />
        <SelectField
          label={t("fleet.model")}
          name="vehicle_model_id"
          options={references.models}
          required
        />
        <Field
          label={t("fleet.modelYear")}
          max={new Date().getFullYear() + 1}
          min={1900}
          name="model_year"
          type="number"
        />
        <Field label={t("fleet.color")} name="color" />
        <Choice
          label={t("fleet.fuelType")}
          name="fuel_type"
          options={["diesel", "petrol", "electric", "hybrid", "other"]}
        />
        <Choice
          label={t("fleet.transmission")}
          name="transmission"
          options={["manual", "automatic", "cvt", "other"]}
        />
        <Field
          label={t("fleet.seatingCapacity")}
          min={1}
          name="seating_capacity"
          type="number"
        />
        <Field
          label={t("fleet.baselineOdometer")}
          min={0}
          name="baseline_odometer_km"
          required
          step="0.1"
          type="number"
        />
      </FormSection>
      <FormSection
        detail={t("fleet.complianceSectionDetail")}
        title={t("fleet.complianceSection")}
      >
        <Field
          label={t("fleet.insuranceExpiry")}
          name="insurance_expiry"
          required
          type="date"
        />
        <Field
          label={t("fleet.registrationExpiry")}
          name="registration_expiry"
          required
          type="date"
        />
        <Field
          label={t("fleet.roadworthinessExpiry")}
          name="roadworthiness_expiry"
          required
          type="date"
        />
      </FormSection>
    </>
  );
}

function DriverFields({
  locale,
  references,
}: {
  readonly locale: Locale;
  readonly references: FleetReferenceData;
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <>
      <FormSection
        detail={t("fleet.driverIdentityDetail")}
        title={t("fleet.driverIdentity")}
      >
        <Field
          label={t("fleet.employeeNumber")}
          name="employee_number"
          required
        />
        <Field label={t("fleet.fullName")} name="full_name" required />
        <Field label={t("fleet.phone")} name="phone" type="tel" />
        <Field label={t("fleet.email")} name="email" type="email" />
        <Field
          full
          label={t("fleet.emergencyContact")}
          name="emergency_contact"
        />
      </FormSection>
      <FormSection
        detail={t("fleet.licenceSectionDetail")}
        title={t("fleet.licenceSection")}
      >
        <Field
          label={t("fleet.licenceNumber")}
          name="licence_number"
          required
        />
        <Field
          label={t("fleet.issuingAuthority")}
          name="issuing_authority"
          required
        />
        <Field
          label={t("fleet.licenceIssued")}
          name="licence_issued_on"
          type="date"
        />
        <Field
          label={t("fleet.licenceExpiry")}
          name="licence_expires_on"
          required
          type="date"
        />
        <SelectField
          label={t("fleet.licenceClass")}
          name="licence_class_id"
          options={references.licence_classes}
          required
        />
      </FormSection>
    </>
  );
}

function AssignmentFields({
  locale,
  vehicles,
  drivers,
}: {
  readonly locale: Locale;
  readonly vehicles: readonly VehicleSummary[];
  readonly drivers: readonly DriverSummary[];
}) {
  const t = (key: Parameters<typeof translate>[1]) => translate(locale, key);
  return (
    <>
      <FormSection
        detail={t("fleet.assignmentPairDetail")}
        title={t("fleet.assignmentPair")}
      >
        <label>
          <span>{t("fleet.vehicle")}</span>
          <select name="vehicle_id" required>
            <option value="">{t("fleet.chooseRecord")}</option>
            {vehicles.map((vehicle) => (
              <option key={vehicle.id} value={vehicle.id}>
                {vehicle.plate_number ?? vehicle.asset_number} ·{" "}
                {vehicle.asset_number}
              </option>
            ))}
          </select>
        </label>
        <label>
          <span>{t("fleet.driver")}</span>
          <select name="driver_id" required>
            <option value="">{t("fleet.chooseRecord")}</option>
            {drivers.map((driver) => (
              <option key={driver.id} value={driver.id}>
                {driver.full_name} · {driver.employee_number}
              </option>
            ))}
          </select>
        </label>
        <Choice
          label={t("fleet.assignmentType")}
          name="assignment_type"
          options={["permanent", "temporary", "pool", "substitute"]}
        />
        <Field
          label={t("fleet.startsAt")}
          name="starts_at"
          required
          type="datetime-local"
        />
        <Field label={t("fleet.endsAt")} name="ends_at" type="datetime-local" />
        <Field
          label={t("fleet.handoverOdometer")}
          min={0}
          name="handover_odometer_km"
          step="0.1"
          type="number"
        />
        <Choice
          label={t("fleet.fuelLevel")}
          name="handover_fuel_level"
          options={["empty", "quarter", "half", "three_quarters", "full"]}
        />
        <Field full label={t("organization.reason")} name="reason" required />
        <Field full label={t("fleet.conditionNotes")} name="condition_notes" />
        <label className="fleet-check">
          <input defaultChecked name="keys_handed_over" type="checkbox" />
          <span>{t("fleet.keysHandedOver")}</span>
        </label>
        <label className="fleet-check">
          <input defaultChecked name="documents_handed_over" type="checkbox" />
          <span>{t("fleet.documentsHandedOver")}</span>
        </label>
        <label className="fleet-check">
          <input
            defaultChecked
            name="acknowledgement_required"
            type="checkbox"
          />
          <span>{t("fleet.acknowledgementRequired")}</span>
        </label>
      </FormSection>
    </>
  );
}

function FormSection({
  title,
  detail,
  children,
}: {
  readonly title: string;
  readonly detail: string;
  readonly children: React.ReactNode;
}) {
  return (
    <fieldset className="fleet-form-section">
      <legend>{title}</legend>
      <p>{detail}</p>
      <div className="fleet-form-grid">{children}</div>
    </fieldset>
  );
}

function Field({
  label,
  name,
  full = false,
  ...input
}: {
  readonly label: string;
  readonly name: string;
  readonly full?: boolean;
  readonly type?: string;
  readonly required?: boolean;
  readonly min?: number;
  readonly max?: number;
  readonly step?: string;
  readonly maxLength?: number;
}) {
  return (
    <label className={full ? "full-field" : undefined}>
      <span>{label}</span>
      <input name={name} {...input} />
    </label>
  );
}

function Choice({
  label,
  name,
  options,
}: {
  readonly label: string;
  readonly name: string;
  readonly options: readonly string[];
}) {
  return (
    <label>
      <span>{label}</span>
      <select name={name} required>
        {options.map((option) => (
          <option key={option} value={option}>
            {option.replaceAll("_", " ")}
          </option>
        ))}
      </select>
    </label>
  );
}

function SelectField({
  label,
  name,
  options,
  required,
}: {
  readonly label: string;
  readonly name: string;
  readonly options: readonly ReferenceRecord[];
  readonly required?: boolean;
}) {
  return (
    <label>
      <span>{label}</span>
      <select name={name} required={required}>
        <option value="">—</option>
        {options.map((option) => (
          <option key={option.id} value={option.id}>
            {typeof option.name === "string" ? option.name : option.name.en}
          </option>
        ))}
      </select>
    </label>
  );
}

function FormState({
  title,
  detail,
}: {
  readonly title: string;
  readonly detail: string;
}) {
  return (
    <section className="fleet-context-state">
      <span className="fleet-context-mark" aria-hidden="true">
        OF
      </span>
      <div>
        <h1>{title}</h1>
        <p>{detail}</p>
      </div>
    </section>
  );
}

function buildRequest(mode: FormMode, form: FormData, organizationId: string) {
  const value = (name: string) => String(form.get(name) ?? "").trim();
  const optional = (name: string) => value(name) || null;
  if (mode === "vehicle") {
    return {
      path: "/vehicles",
      body: {
        asset_number: value("asset_number"),
        plate_number: value("plate_number"),
        vin: optional("vin"),
        chassis_number: value("chassis_number"),
        engine_number: optional("engine_number"),
        registration_number: optional("registration_number"),
        vehicle_category_id: value("vehicle_category_id"),
        vehicle_class_id: value("vehicle_class_id"),
        manufacturer_id: value("manufacturer_id"),
        vehicle_model_id: value("vehicle_model_id"),
        owning_organization_id: organizationId,
        custodian_organization_id: organizationId,
        ownership_type: "owned",
        model_year: optional("model_year") ? Number(value("model_year")) : null,
        color: optional("color"),
        fuel_type: value("fuel_type"),
        transmission: value("transmission"),
        seating_capacity: optional("seating_capacity")
          ? Number(value("seating_capacity"))
          : null,
        baseline_odometer_km: Number(value("baseline_odometer_km")),
        compliance: [
          ["insurance", value("insurance_expiry")],
          ["registration", value("registration_expiry")],
          ["roadworthiness", value("roadworthiness_expiry")],
        ].map(([document_type, expires_on]) => ({
          document_type,
          expires_on,
        })),
      },
    };
  }
  if (mode === "driver") {
    return {
      path: "/drivers",
      body: {
        employee_number: value("employee_number"),
        organization_id: organizationId,
        full_name: value("full_name"),
        phone: optional("phone"),
        email: optional("email"),
        emergency_contact: optional("emergency_contact"),
        employment_status: "active",
        status: "active",
        availability_status: "available",
        licence: {
          number: value("licence_number"),
          issuing_authority: value("issuing_authority"),
          issued_on: optional("licence_issued_on"),
          expires_on: value("licence_expires_on"),
          status: "pending_verification",
          class_ids: [value("licence_class_id")],
        },
      },
    };
  }
  return {
    path: "/vehicle-driver-assignments",
    body: {
      vehicle_id: value("vehicle_id"),
      driver_id: value("driver_id"),
      organization_id: organizationId,
      assignment_type: value("assignment_type"),
      exclusive: true,
      starts_at: value("starts_at"),
      ends_at: optional("ends_at"),
      reason: value("reason"),
      handover_odometer_km: optional("handover_odometer_km")
        ? Number(value("handover_odometer_km"))
        : null,
      handover_fuel_level: optional("handover_fuel_level"),
      keys_handed_over: form.has("keys_handed_over"),
      documents_handed_over: form.has("documents_handed_over"),
      condition_notes: optional("condition_notes"),
      acknowledgement_required: form.has("acknowledgement_required"),
    },
  };
}
