"use client";

import { useEffect, useMemo, useState, type FormEvent } from "react";
import Link from "next/link";
import { translate, type Locale } from "@oromia/localization";
import { ApiProblem, apiRequest } from "@/platform/api-client";
import {
  organizationPermissions,
  type OrganizationPermission,
} from "@/organization/permissions";
import type {
  ApiEnvelope,
  HierarchyMovePreview,
  OrganizationSummary,
  OrganizationTreeNode,
  OrganizationType,
} from "@/organization/types";
import { OrganizationTree } from "./OrganizationTree";

type ViewState =
  | "loading"
  | "ready"
  | "empty"
  | "unauthorized"
  | "forbidden"
  | "not-found"
  | "conflict"
  | "error";

export function OrganizationWorkspace({
  locale,
  permissions,
}: {
  readonly locale: Locale;
  readonly permissions: readonly OrganizationPermission[];
}) {
  const hasHierarchyView = permissions.includes(
    organizationPermissions.hierarchyView,
  );
  const [state, setState] = useState<ViewState>(
    hasHierarchyView ? "loading" : "forbidden",
  );
  const [types, setTypes] = useState<readonly OrganizationType[]>([]);
  const [organizations, setOrganizations] = useState<
    readonly OrganizationSummary[]
  >([]);
  const [tree, setTree] = useState<readonly OrganizationTreeNode[]>([]);
  const [preview, setPreview] = useState<HierarchyMovePreview | null>(null);
  const [operationMessage, setOperationMessage] = useState<string | null>(null);
  const can = useMemo(
    () => (permission: OrganizationPermission) =>
      permissions.includes(permission),
    [permissions],
  );

  useEffect(() => {
    if (!hasHierarchyView) return;
    let active = true;
    Promise.all([
      apiRequest<ApiEnvelope<OrganizationType[]>>("/organization-types"),
      apiRequest<ApiEnvelope<OrganizationSummary[]>>("/organizations"),
      apiRequest<ApiEnvelope<OrganizationTreeNode[]>>("/organizations/tree"),
    ])
      .then(([typeResponse, organizationResponse, treeResponse]) => {
        if (!active) return;
        setTypes(typeResponse.data);
        setOrganizations(organizationResponse.data);
        setTree(treeResponse.data);
        setState(
          typeResponse.data.length + organizationResponse.data.length === 0
            ? "empty"
            : "ready",
        );
      })
      .catch((error: unknown) => {
        if (!active) return;
        setState(problemState(error));
      });

    return () => {
      active = false;
    };
  }, [can, hasHierarchyView]);

  async function previewMove(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    if (
      !form.get("source_organization_id") ||
      !form.get("proposed_parent_organization_id") ||
      !form.get("reason")
    ) {
      setState("error");
      return;
    }
    try {
      const response = await apiRequest<ApiEnvelope<HierarchyMovePreview>>(
        "/organization-move-previews",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Idempotency-Key": crypto.randomUUID(),
          },
          body: JSON.stringify(Object.fromEntries(form)),
        },
      );
      setPreview(response.data);
      setState("ready");
    } catch (error) {
      setState(problemState(error));
    }
  }

  async function requestMove() {
    if (!preview) return;
    try {
      await apiRequest("/organization-moves", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": crypto.randomUUID(),
        },
        body: JSON.stringify({
          preview_id: preview.id,
          preview_version: preview.preview_version,
          requested_effective_at: preview.requested_effective_at,
          reason: translate(locale, "organization.moveRequestReason"),
        }),
      });
      setOperationMessage(translate(locale, "state.saved"));
    } catch (error) {
      setOperationMessage(
        error instanceof ApiProblem && error.problem.status === 409
          ? translate(locale, "state.conflict")
          : translate(locale, "state.validation"),
      );
    }
  }

  if (state === "loading") {
    return <p role="status">{translate(locale, "state.loading")}</p>;
  }
  if (state !== "ready" && state !== "empty") {
    return (
      <div className="state-box" role="alert">
        {translate(locale, stateTranslationKey(state))}
      </div>
    );
  }

  return (
    <div className="organization-workspace">
      <header>
        <p className="eyebrow">{translate(locale, "app.name")}</p>
        <h1>{translate(locale, "organization.title")}</h1>
        <p>{translate(locale, "organization.description")}</p>
        <p className="warning-banner">
          {translate(locale, "organization.productionBlocked")}
        </p>
      </header>

      <nav
        className="section-nav"
        aria-label={translate(locale, "organization.title")}
      >
        <a href="#types">{translate(locale, "organization.types")}</a>
        <a href="#tree">{translate(locale, "organization.tree")}</a>
        <a href="#move">{translate(locale, "organization.movePreview")}</a>
        <a href="#configuration">
          {translate(locale, "organization.settings")}
        </a>
        <Link href="/organizations/history">
          {translate(locale, "organization.history")}
        </Link>
      </nav>

      <section
        className="workspace-grid"
        id="types"
        aria-labelledby="types-title"
      >
        <div className="panel">
          <h2 id="types-title">{translate(locale, "organization.types")}</h2>
          {types.length === 0 ? (
            <p className="empty-state">{translate(locale, "state.empty")}</p>
          ) : (
            <div className="table-scroll">
              <table>
                <thead>
                  <tr>
                    <th scope="col">
                      {translate(locale, "organization.code")}
                    </th>
                    <th scope="col">
                      {translate(locale, "organization.name")}
                    </th>
                    <th scope="col">
                      {translate(locale, "organization.status")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {types.map((type) => (
                    <tr key={type.id}>
                      <th scope="row">{type.code}</th>
                      <td>{type.name_key}</td>
                      <td>
                        <span className="status-badge">{type.status}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {can(organizationPermissions.typeCreate) && (
            <Link className="button-link" href="/organizations/types/new">
              {translate(locale, "organization.createType")}
            </Link>
          )}
        </div>

        <div className="panel">
          <h2>{translate(locale, "organization.nodes")}</h2>
          <p>
            {organizations.length}{" "}
            {translate(locale, "organization.configured")}
          </p>
          {can(organizationPermissions.nodeCreate) && (
            <Link className="button-link" href="/organizations/new">
              {translate(locale, "organization.createNode")}
            </Link>
          )}
        </div>
      </section>

      <section className="panel" id="tree" aria-labelledby="tree-title">
        <h2 id="tree-title">{translate(locale, "organization.tree")}</h2>
        {tree.length === 0 ? (
          <p className="empty-state">{translate(locale, "state.empty")}</p>
        ) : (
          <OrganizationTree nodes={tree} locale={locale} />
        )}
      </section>

      <section className="panel" id="move" aria-labelledby="move-title">
        <h2 id="move-title">{translate(locale, "organization.movePreview")}</h2>
        {can(organizationPermissions.hierarchyPreview) ? (
          <form className="form-grid" onSubmit={previewMove}>
            <label>
              {translate(locale, "organization.details")}
              <select name="source_organization_id" required>
                <option value="">—</option>
                {organizations.map((organization) => (
                  <option key={organization.id} value={organization.id}>
                    {organization.name[locale]}
                  </option>
                ))}
              </select>
            </label>
            <label>
              {translate(locale, "organization.proposedParent")}
              <select name="proposed_parent_organization_id" required>
                <option value="">—</option>
                {organizations.map((organization) => (
                  <option key={organization.id} value={organization.id}>
                    {organization.name[locale]}
                  </option>
                ))}
              </select>
            </label>
            <label>
              {translate(locale, "organization.effectiveDate")}
              <input
                name="requested_effective_at"
                type="datetime-local"
                required
              />
            </label>
            <label className="full-field">
              {translate(locale, "organization.reason")}
              <textarea name="reason" minLength={3} maxLength={2000} required />
            </label>
            <button type="submit">{translate(locale, "action.preview")}</button>
          </form>
        ) : (
          <p>{translate(locale, "state.forbidden")}</p>
        )}
        {preview && <ImpactPreview locale={locale} preview={preview} />}
        {preview &&
          preview.blockers.length === 0 &&
          can(organizationPermissions.moveRequest) && (
            <button type="button" onClick={() => void requestMove()}>
              {translate(locale, "organization.requestMove")}
            </button>
          )}
        {operationMessage && (
          <p className="notice" role="status">
            {operationMessage}
          </p>
        )}
      </section>

      <section
        className="panel"
        id="configuration"
        aria-label={translate(locale, "organization.settings")}
      >
        <h2>{translate(locale, "organization.configurationRegister")}</h2>
        <p>{translate(locale, "organization.configurationHelp")}</p>
        {organizations.length === 0 ? (
          <p className="empty-state">{translate(locale, "state.empty")}</p>
        ) : (
          <div className="card-register">
            {organizations.map((organization) => (
              <Link
                className="register-card"
                href={`/organizations/${organization.id}`}
                key={organization.id}
              >
                <span className="eyebrow">{organization.code}</span>
                <strong>{organization.name[locale]}</strong>
                <span>
                  {translate(locale, "organization.manageConfiguration")}
                </span>
              </Link>
            ))}
          </div>
        )}
      </section>
      <div className="action-group">
        <Link className="button-link" href="/organizations/types">
          {translate(locale, "organization.typeRegister")}
        </Link>
        <Link className="button-link" href="/organizations/moves/new">
          {translate(locale, "organization.scheduledChanges")}
        </Link>
      </div>
    </div>
  );
}

function ImpactPreview({
  preview,
  locale,
}: {
  readonly preview: HierarchyMovePreview;
  readonly locale: Locale;
}) {
  return (
    <div className="impact-preview" role="status" aria-live="polite">
      <h3>{translate(locale, "organization.permissionImpact")}</h3>
      <ul>
        {preview.warnings.map((warning) => (
          <li key={warning}>{warning}</li>
        ))}
      </ul>
      <h3>{translate(locale, "organization.workflowImpact")}</h3>
      <pre>{JSON.stringify(preview.workflow_impact, null, 2)}</pre>
      <h3>{translate(locale, "organization.configurationImpact")}</h3>
      <pre>{JSON.stringify(preview.configuration_impact, null, 2)}</pre>
      {preview.blockers.length > 0 && (
        <div className="error-banner" role="alert">
          {preview.blockers.join(", ")}
        </div>
      )}
    </div>
  );
}

function problemState(error: unknown): ViewState {
  if (!(error instanceof ApiProblem)) return "error";
  if (error.problem.status === 401) return "unauthorized";
  if (error.problem.status === 403) return "forbidden";
  if (error.problem.status === 404) return "not-found";
  if (error.problem.status === 409) return "conflict";
  return "error";
}

function stateTranslationKey(state: ViewState) {
  if (state === "not-found") return "state.notFound" as const;
  if (state === "conflict") return "state.conflict" as const;
  if (state === "unauthorized") return "state.unauthorized" as const;
  if (state === "forbidden") return "state.forbidden" as const;
  return "state.unavailable" as const;
}
