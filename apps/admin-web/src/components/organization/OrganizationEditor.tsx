"use client";

import { useState, type FormEvent } from "react";
import { translate, type Locale } from "@oromia/localization";
import { ApiProblem, apiRequest } from "@/platform/api-client";

export function OrganizationEditor({
  locale,
  resource,
}: {
  readonly locale: Locale;
  readonly resource: "type" | "node";
}) {
  const [message, setMessage] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const code = String(form.get("code") ?? "").trim();
    const names = {
      en: String(form.get("name_en") ?? "").trim(),
      om: String(form.get("name_om") ?? "").trim(),
      am: String(form.get("name_am") ?? "").trim(),
    };
    if (!code || Object.values(names).some((name) => !name)) {
      setMessage(translate(locale, "state.validation"));
      return;
    }
    const payload =
      resource === "type"
        ? {
            code,
            name_key: `organization.type.${code.toLowerCase()}`,
            translations: names,
            description: String(form.get("description") ?? ""),
            sort_order: Number(form.get("sort_order") ?? 0),
            may_be_root: form.get("may_be_root") === "on",
            effective_from: form.get("effective_from"),
          }
        : {
            type_id: form.get("type_id"),
            code,
            name: names,
            description: String(form.get("description") ?? ""),
            parent_id: form.get("parent_id") || null,
            effective_from: form.get("effective_from"),
          };
    try {
      await apiRequest(
        resource === "type" ? "/organization-types" : "/organizations",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Idempotency-Key": crypto.randomUUID(),
          },
          body: JSON.stringify(payload),
        },
      );
      setMessage(translate(locale, "action.save"));
      event.currentTarget.reset();
    } catch (error) {
      setMessage(
        error instanceof ApiProblem && error.problem.status === 409
          ? translate(locale, "state.conflict")
          : translate(locale, "state.unavailable"),
      );
    }
  }

  return (
    <form className="panel form-grid" onSubmit={submit} noValidate>
      <h1 className="full-field">
        {translate(
          locale,
          resource === "type"
            ? "organization.createType"
            : "organization.createNode",
        )}
      </h1>
      <label>
        {translate(locale, "organization.code")}
        <input name="code" maxLength={80} required />
      </label>
      <label>
        {translate(locale, "language.en")} —{" "}
        {translate(locale, "organization.name")}
        <input name="name_en" maxLength={255} required />
      </label>
      <label>
        {translate(locale, "language.om")} —{" "}
        {translate(locale, "organization.name")}
        <input name="name_om" maxLength={255} required />
      </label>
      <label>
        {translate(locale, "language.am")} —{" "}
        {translate(locale, "organization.name")}
        <input name="name_am" maxLength={255} required />
      </label>
      {resource === "node" && (
        <>
          <label>
            {translate(locale, "organization.typeReference")}
            <input name="type_id" minLength={26} maxLength={26} required />
          </label>
          <label>
            {translate(locale, "organization.currentParent")}
            <input name="parent_id" minLength={26} maxLength={26} />
          </label>
        </>
      )}
      {resource === "type" && (
        <>
          <label>
            {translate(locale, "organization.sortOrder")}
            <input
              name="sort_order"
              type="number"
              min={0}
              max={10000}
              required
            />
          </label>
          <label className="checkbox-field">
            <input name="may_be_root" type="checkbox" />
            {translate(locale, "organization.mayBeRoot")}
          </label>
        </>
      )}
      <label>
        {translate(locale, "organization.effectiveDate")}
        <input name="effective_from" type="datetime-local" required />
      </label>
      <label className="full-field">
        {translate(locale, "organization.descriptionLabel")}
        <textarea name="description" maxLength={2000} required />
      </label>
      <button type="submit">{translate(locale, "action.save")}</button>
      {message && (
        <p role="status" aria-live="polite">
          {message}
        </p>
      )}
    </form>
  );
}
