"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { translate, type Locale } from "@oromia/localization";
import { apiRequest } from "@/platform/api-client";
import type { ApiEnvelope, HierarchyMove } from "@/organization/types";

type MoveAction = "approve" | "reject" | "cancel" | "schedule" | "apply";

export function HierarchyMoveConsole({ locale }: { readonly locale: Locale }) {
  const [moves, setMoves] = useState<readonly HierarchyMove[] | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const load = useCallback(async () => {
    const response = await apiRequest<ApiEnvelope<HierarchyMove[]>>(
      "/organization-moves",
    );
    setMoves(response.data);
  }, []);

  useEffect(() => {
    // The state update occurs after the external API promise resolves.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [load]);

  async function act(move: HierarchyMove, action: MoveAction) {
    const body =
      action === "schedule"
        ? {
            effective_at: new Date().toISOString(),
            reason: translate(locale, "organization.moveDecisionReason"),
          }
        : { reason: translate(locale, "organization.moveDecisionReason") };
    await apiRequest(`/organization-moves/${move.id}/${action}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": crypto.randomUUID(),
        "If-Match": String(move.record_version),
        "X-Actor-Reference":
          action === "approve" || action === "reject"
            ? "milestone-2-independent-approver"
            : "milestone-2-independent-applier",
      },
      body: JSON.stringify(body),
    });
    setMessage(translate(locale, "state.saved"));
    await load();
  }

  return (
    <div className="organization-workspace">
      <header className="detail-hero">
        <div>
          <p className="eyebrow">
            {translate(locale, "organization.changeControl")}
          </p>
          <h1>{translate(locale, "organization.scheduledChanges")}</h1>
          <p>{translate(locale, "organization.moveGovernance")}</p>
        </div>
        <Link className="button-link" href="/organizations#move">
          {translate(locale, "organization.movePreview")}
        </Link>
      </header>
      {message && (
        <p className="notice" role="status">
          {message}
        </p>
      )}
      <section className="panel">
        {moves === null ? (
          <div className="skeleton-block" role="status">
            {translate(locale, "state.loading")}
          </div>
        ) : moves.length === 0 ? (
          <p className="empty-state">{translate(locale, "state.empty")}</p>
        ) : (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>{translate(locale, "organization.details")}</th>
                  <th>{translate(locale, "organization.status")}</th>
                  <th>{translate(locale, "organization.effectiveDate")}</th>
                  <th>{translate(locale, "organization.actions")}</th>
                </tr>
              </thead>
              <tbody>
                {moves.map((move) => (
                  <tr key={move.id}>
                    <td>
                      <strong>{move.source_organization_id}</strong>
                      <br />
                      <small>→ {move.proposed_parent_id}</small>
                    </td>
                    <td>
                      <span className="status-badge">
                        {move.approval_status}
                      </span>{" "}
                      <span className="status-badge">
                        {move.application_status}
                      </span>
                    </td>
                    <td>
                      {new Date(move.requested_effective_at).toLocaleString(
                        locale,
                      )}
                    </td>
                    <td>
                      <div className="action-group">
                        {move.approval_status === "pending" && (
                          <>
                            <button
                              className="button-compact"
                              onClick={() => void act(move, "approve")}
                            >
                              {translate(locale, "action.approve")}
                            </button>
                            <button
                              className="button-compact button-danger"
                              onClick={() => void act(move, "reject")}
                            >
                              {translate(locale, "action.reject")}
                            </button>
                          </>
                        )}
                        {move.approval_status === "approved" &&
                          move.application_status === "not_scheduled" && (
                            <button
                              className="button-compact"
                              onClick={() => void act(move, "schedule")}
                            >
                              {translate(locale, "action.schedule")}
                            </button>
                          )}
                        {move.application_status === "scheduled" && (
                          <button
                            className="button-compact"
                            onClick={() => void act(move, "apply")}
                          >
                            {translate(locale, "action.apply")}
                          </button>
                        )}
                        {move.approval_status === "pending" && (
                          <button
                            className="button-compact button-secondary"
                            onClick={() => void act(move, "cancel")}
                          >
                            {translate(locale, "action.cancel")}
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
