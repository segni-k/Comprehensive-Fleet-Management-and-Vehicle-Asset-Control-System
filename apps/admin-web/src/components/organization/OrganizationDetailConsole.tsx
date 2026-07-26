"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { translate, type Locale } from "@oromia/localization";
import { ApiProblem, apiRequest } from "@/platform/api-client";
import type {
  ApiEnvelope,
  AuditEvent,
  EffectiveSetting,
  OrganizationContact,
  OrganizationManager,
  OrganizationSetting,
  OrganizationSummary,
} from "@/organization/types";

type DetailData = {
  organization: OrganizationSummary;
  contacts: readonly OrganizationContact[];
  managers: readonly OrganizationManager[];
  localSettings: readonly OrganizationSetting[];
  settings: readonly EffectiveSetting[];
  history: readonly AuditEvent[];
};

export function OrganizationDetailConsole({
  id,
  locale,
}: {
  readonly id: string;
  readonly locale: Locale;
}) {
  const [data, setData] = useState<DetailData | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "empty" | "error">(
    "loading",
  );
  const [message, setMessage] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [
        organization,
        contacts,
        managers,
        localSettings,
        settings,
        history,
      ] = await Promise.all([
        apiRequest<ApiEnvelope<OrganizationSummary>>(`/organizations/${id}`),
        apiRequest<ApiEnvelope<OrganizationContact[]>>(
          `/organizations/${id}/contacts`,
        ),
        apiRequest<ApiEnvelope<OrganizationManager[]>>(
          `/organizations/${id}/managers`,
        ),
        apiRequest<ApiEnvelope<OrganizationSetting[]>>(
          `/organizations/${id}/settings`,
        ),
        apiRequest<ApiEnvelope<EffectiveSetting[]>>(
          `/organizations/${id}/settings/effective`,
        ),
        apiRequest<ApiEnvelope<AuditEvent[]>>(`/organizations/${id}/history`),
      ]);
      setData({
        organization: organization.data,
        contacts: contacts.data,
        managers: managers.data,
        localSettings: localSettings.data,
        settings: settings.data,
        history: history.data,
      });
      setState("ready");
    } catch {
      setState("error");
    }
  }, [id]);

  useEffect(() => {
    // The state updates occur after the external API promises resolve.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [load]);

  async function createContact(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await submitForm(event.currentTarget, `/organizations/${id}/contacts`, {
      is_primary:
        event.currentTarget.elements.namedItem("is_primary") instanceof
        HTMLInputElement
          ? (
              event.currentTarget.elements.namedItem(
                "is_primary",
              ) as HTMLInputElement
            ).checked
          : false,
    });
  }

  async function createManager(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await submitForm(event.currentTarget, `/organizations/${id}/managers`, {
      delegation_restricted: true,
    });
  }

  async function createSetting(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const values = Object.fromEntries(new FormData(form));
    try {
      await apiRequest(`/organizations/${id}/settings`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
        },
        body: JSON.stringify({
          ...values,
          value: JSON.parse(String(values.value)),
        }),
      });
      form.reset();
      setMessage(translate(locale, "state.saved"));
      await load();
    } catch {
      setMessage(translate(locale, "state.validation"));
    }
  }

  async function submitForm(
    form: HTMLFormElement,
    path: string,
    extra: Record<string, unknown>,
  ) {
    const values = Object.fromEntries(new FormData(form));
    try {
      await apiRequest(path, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
        },
        body: JSON.stringify({ ...values, ...extra }),
      });
      form.reset();
      setMessage(translate(locale, "state.saved"));
      await load();
    } catch (error) {
      setMessage(
        error instanceof ApiProblem && error.problem.status === 409
          ? translate(locale, "state.conflict")
          : translate(locale, "state.validation"),
      );
    }
  }

  async function changeStatus(action: "activate" | "deactivate") {
    if (!data) return;
    try {
      await apiRequest(`/organizations/${id}/${action}`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
          "If-Match": String(data.organization.record_version),
        },
        body: JSON.stringify({
          reason: translate(locale, "organization.statusReason"),
          effective_at: new Date().toISOString(),
        }),
      });
      setMessage(translate(locale, "state.saved"));
      await load();
    } catch {
      setMessage(translate(locale, "state.conflict"));
    }
  }

  if (state === "loading") {
    return (
      <div className="skeleton-block" role="status">
        {translate(locale, "state.loading")}
      </div>
    );
  }
  if (state === "error" || !data) {
    return (
      <div className="state-box" role="alert">
        {translate(locale, "state.unavailable")}
      </div>
    );
  }

  return (
    <div className="organization-workspace">
      <nav
        className="breadcrumbs"
        aria-label={translate(locale, "nav.breadcrumbs")}
      >
        <Link href="/organizations">
          {translate(locale, "organization.title")}
        </Link>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{data.organization.name[locale]}</span>
      </nav>

      <header className="detail-hero">
        <div>
          <p className="eyebrow">{data.organization.code}</p>
          <h1>{data.organization.name[locale]}</h1>
          <p>{data.organization.description}</p>
        </div>
        <div className="hero-actions">
          <span className={`status-badge status-${data.organization.status}`}>
            {data.organization.status}
          </span>
          <button type="button" onClick={() => void changeStatus("activate")}>
            {translate(locale, "organization.activate")}
          </button>
          <button
            className="button-secondary"
            type="button"
            onClick={() => void changeStatus("deactivate")}
          >
            {translate(locale, "organization.deactivate")}
          </button>
        </div>
      </header>

      {message && (
        <p className="notice" role="status">
          {message}
        </p>
      )}

      <div className="workspace-grid">
        <section className="panel">
          <h2>{translate(locale, "organization.contacts")}</h2>
          <DataTable
            locale={locale}
            rows={data.contacts.map((contact) => [
              contact.contact_type,
              contact.value,
              contact.status,
            ])}
          />
          <form className="form-stack compact-form" onSubmit={createContact}>
            <label>
              {translate(locale, "organization.contactType")}
              <input name="contact_type" required maxLength={50} />
            </label>
            <label>
              {translate(locale, "organization.contactValue")}
              <input name="value" required maxLength={500} />
            </label>
            <label>
              {translate(locale, "organization.effectiveDate")}
              <input name="effective_from" type="datetime-local" required />
            </label>
            <label className="checkbox-field">
              <input name="is_primary" type="checkbox" />
              {translate(locale, "organization.primary")}
            </label>
            <button type="submit">{translate(locale, "action.add")}</button>
          </form>
        </section>

        <section className="panel">
          <h2>{translate(locale, "organization.managers")}</h2>
          <DataTable
            locale={locale}
            rows={data.managers.map((manager) => [
              manager.user_id,
              manager.appointing_authority,
              manager.status,
            ])}
          />
          <form className="form-stack compact-form" onSubmit={createManager}>
            <label>
              {translate(locale, "organization.userReference")}
              <input name="user_id" minLength={26} maxLength={26} required />
            </label>
            <label>
              {translate(locale, "organization.responsibility")}
              <input name="responsibility_code" required maxLength={80} />
            </label>
            <label>
              {translate(locale, "organization.appointingAuthority")}
              <input name="appointing_authority" required maxLength={500} />
            </label>
            <label>
              {translate(locale, "organization.effectiveDate")}
              <input name="effective_from" type="datetime-local" required />
            </label>
            <button type="submit">{translate(locale, "action.assign")}</button>
          </form>
        </section>
      </div>

      <section className="panel">
        <h2>{translate(locale, "organization.settings")}</h2>
        <DataTable
          locale={locale}
          rows={data.localSettings.map((setting) => [
            setting.setting_definition_id,
            readableValue(setting.value),
            new Date(setting.effective_from).toLocaleString(locale),
          ])}
        />
        <form className="form-grid section-form" onSubmit={createSetting}>
          <label>
            {translate(locale, "organization.settingDefinition")}
            <input
              name="setting_definition_id"
              minLength={26}
              maxLength={26}
              required
            />
          </label>
          <label>
            {translate(locale, "organization.settingValue")}
            <input name="value" defaultValue={'{"value":""}'} required />
          </label>
          <label>
            {translate(locale, "organization.effectiveDate")}
            <input name="effective_from" type="datetime-local" required />
          </label>
          <button type="submit">
            {translate(locale, "action.addSetting")}
          </button>
        </form>
      </section>

      <section className="panel">
        <h2>{translate(locale, "organization.inheritedSettings")}</h2>
        <DataTable
          locale={locale}
          rows={data.settings.map((setting) => [
            setting.setting_definition_id,
            readableValue(setting.value),
            setting.override_status,
          ])}
        />
      </section>

      <section className="panel">
        <h2>{translate(locale, "organization.history")}</h2>
        <AuditTimeline events={data.history} locale={locale} />
      </section>
    </div>
  );
}

function DataTable({
  rows,
  locale,
}: {
  readonly rows: readonly (readonly string[])[];
  readonly locale: Locale;
}) {
  if (rows.length === 0) {
    return <p className="empty-state">{translate(locale, "state.empty")}</p>;
  }
  return (
    <div className="table-scroll">
      <table>
        <tbody>
          {rows.map((row, index) => (
            <tr key={`${row[0]}-${index}`}>
              {row.map((value) => (
                <td key={value}>{value}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function AuditTimeline({
  events,
  locale,
}: {
  readonly events: readonly AuditEvent[];
  readonly locale: Locale;
}) {
  if (events.length === 0) {
    return <p className="empty-state">{translate(locale, "state.empty")}</p>;
  }
  return (
    <ol className="audit-timeline">
      {events.map((event) => (
        <li key={event.id}>
          <div className="timeline-marker" aria-hidden="true" />
          <div>
            <strong>{event.event_type}</strong>
            <p>{event.reason}</p>
            <small>
              {event.actor_reference} ·{" "}
              {new Date(event.occurred_at).toLocaleString(locale)}
            </small>
          </div>
        </li>
      ))}
    </ol>
  );
}

function readableValue(value: unknown): string {
  return typeof value === "string" ? value : JSON.stringify(value);
}
