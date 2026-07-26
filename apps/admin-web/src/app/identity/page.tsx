const workspaces = [
  [
    "People & access",
    "Users, lifecycle status, MFA readiness and active sessions",
    "12 active",
  ],
  [
    "Roles & permissions",
    "Versioned role templates and permission matrices",
    "No production grants",
  ],
  [
    "Scoped assignments",
    "Current node, descendants, selected children and records",
    "3 pending review",
  ],
  [
    "Delegations",
    "Time-bound authority with source and scope evidence",
    "1 expires soon",
  ],
  [
    "Access reviews",
    "Independent certification of standing and temporary access",
    "4 due this week",
  ],
  [
    "Emergency access",
    "Critical break-glass events requiring independent review",
    "0 active",
  ],
] as const;

export default function IdentityPage() {
  return (
    <div className="identity-workspace">
      <header className="page-hero identity-hero">
        <div>
          <span className="eyebrow">IDENTITY & ACCESS GOVERNANCE</span>
          <h1>Authority that stays visible, scoped and accountable</h1>
          <p>
            Govern who can act, where they can act, and for how long—without
            turning organization management records into permissions.
          </p>
        </div>
        <div className="risk-summary" aria-label="Current access posture">
          <strong>Controlled posture</strong>
          <span>Role bundles remain inactive pending owner approval</span>
        </div>
      </header>
      <nav className="identity-tabs" aria-label="Identity workspaces">
        <a href="/identity" aria-current="page">
          Overview
        </a>
        <a href="#people">People</a>
        <a href="#roles">Roles</a>
        <a href="#reviews">Reviews</a>
        <a href="#audit">Audit</a>
      </nav>
      <section className="identity-metrics" aria-label="Identity summary">
        <article>
          <span>Pending decisions</span>
          <strong>7</strong>
          <small>Maker-checker queue</small>
        </article>
        <article>
          <span>Privileged access</span>
          <strong>0</strong>
          <small>No approved production grants</small>
        </article>
        <article>
          <span>Sessions at risk</span>
          <strong>0</strong>
          <small>Revocation monitoring clear</small>
        </article>
        <article>
          <span>Review completion</span>
          <strong>86%</strong>
          <small>Current review cycle</small>
        </article>
      </section>
      <section className="identity-grid" id="people">
        {workspaces.map(([title, description, status]) => (
          <article className="identity-module-card" key={title}>
            <div className="module-card-heading">
              <h2>{title}</h2>
              <span>{status}</span>
            </div>
            <p>{description}</p>
            <button
              type="button"
              className="secondary-button"
              aria-label={`Open ${title}`}
            >
              Open workspace <span aria-hidden="true">→</span>
            </button>
          </article>
        ))}
      </section>
      <aside className="governance-notice" id="audit">
        <div>
          <strong>Configuration boundary in force</strong>
          <p>
            Permission codes are available for integration. Role bundles,
            approval limits and production assignments require owner-approved
            configuration.
          </p>
        </div>
        <button type="button" className="secondary-button">
          View decision record
        </button>
      </aside>
    </div>
  );
}
