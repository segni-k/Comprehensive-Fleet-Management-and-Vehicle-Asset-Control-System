"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { translate, type Locale } from "@oromia/localization";
import { apiRequest } from "@/platform/api-client";
import type {
  ApiEnvelope,
  OrganizationType,
  OrganizationTypeRule,
} from "@/organization/types";

export function OrganizationTypesConsole({
  locale,
}: {
  readonly locale: Locale;
}) {
  const [types, setTypes] = useState<readonly OrganizationType[]>([]);
  const [rules, setRules] = useState<readonly OrganizationTypeRule[]>([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [typeResponse, ruleResponse] = await Promise.all([
        apiRequest<ApiEnvelope<OrganizationType[]>>("/organization-types"),
        apiRequest<ApiEnvelope<OrganizationTypeRule[]>>(
          "/organization-type-rules",
        ),
      ]);
      setTypes(typeResponse.data);
      setRules(ruleResponse.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function createRule(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    await apiRequest("/organization-type-rules", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": crypto.randomUUID(),
      },
      body: JSON.stringify({
        ...Object.fromEntries(new FormData(form)),
        status: "active",
      }),
    });
    form.reset();
    setMessage(translate(locale, "state.saved"));
    await load();
  }

  async function changeStatus(type: OrganizationType) {
    const action = type.status === "active" ? "deactivate" : "activate";
    await apiRequest(`/organization-types/${type.id}/${action}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": crypto.randomUUID(),
        "If-Match": String(type.record_version),
      },
      body: JSON.stringify({
        reason: translate(locale, "organization.statusReason"),
        effective_at: new Date().toISOString(),
      }),
    });
    await load();
  }

  return (
    <div className="organization-workspace">
      <header className="detail-hero">
        <div>
          <p className="eyebrow">{translate(locale, "app.name")}</p>
          <h1>{translate(locale, "organization.types")}</h1>
          <p>{translate(locale, "organization.typeGovernance")}</p>
        </div>
        <Link className="button-link" href="/organizations/types/new">
          {translate(locale, "organization.createType")}
        </Link>
      </header>
      {message && (
        <p className="notice" role="status">
          {message}
        </p>
      )}
      <section className="panel">
        <h2>{translate(locale, "organization.typeRegister")}</h2>
        {loading ? (
          <div className="skeleton-block" role="status">
            {translate(locale, "state.loading")}
          </div>
        ) : types.length === 0 ? (
          <p className="empty-state">{translate(locale, "state.empty")}</p>
        ) : (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>{translate(locale, "organization.code")}</th>
                  <th>{translate(locale, "organization.descriptionLabel")}</th>
                  <th>{translate(locale, "organization.status")}</th>
                  <th>{translate(locale, "organization.actions")}</th>
                </tr>
              </thead>
              <tbody>
                {types.map((type) => (
                  <tr key={type.id}>
                    <th scope="row">{type.code}</th>
                    <td>{type.description}</td>
                    <td>
                      <span className={`status-badge status-${type.status}`}>
                        {type.status}
                      </span>
                    </td>
                    <td>
                      <button
                        className="button-compact"
                        type="button"
                        onClick={() => void changeStatus(type)}
                      >
                        {translate(
                          locale,
                          type.status === "active"
                            ? "organization.deactivate"
                            : "organization.activate",
                        )}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      <section className="panel">
        <h2>{translate(locale, "organization.typeRules")}</h2>
        {rules.length === 0 ? (
          <p className="empty-state">{translate(locale, "state.empty")}</p>
        ) : (
          <div className="table-scroll">
            <table>
              <tbody>
                {rules.map((rule) => (
                  <tr key={rule.id}>
                    <td>{typeCode(types, rule.parent_type_id)}</td>
                    <td
                      aria-label={translate(locale, "organization.allowsChild")}
                    >
                      →
                    </td>
                    <td>{typeCode(types, rule.child_type_id)}</td>
                    <td>
                      <span className="status-badge">{rule.status}</span>
                    </td>
                    <td>
                      {new Date(rule.effective_from).toLocaleDateString(locale)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <form className="form-grid section-form" onSubmit={createRule}>
          <label>
            {translate(locale, "organization.parentType")}
            <select name="parent_type_id" required>
              <option value="">—</option>
              {types.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.code}
                </option>
              ))}
            </select>
          </label>
          <label>
            {translate(locale, "organization.childType")}
            <select name="child_type_id" required>
              <option value="">—</option>
              {types.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.code}
                </option>
              ))}
            </select>
          </label>
          <label>
            {translate(locale, "organization.effectiveDate")}
            <input name="effective_from" type="datetime-local" required />
          </label>
          <button type="submit">{translate(locale, "action.addRule")}</button>
        </form>
      </section>
    </div>
  );
}

function typeCode(types: readonly OrganizationType[], id: string): string {
  return types.find((type) => type.id === id)?.code ?? id;
}
