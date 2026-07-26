"use client";

import { useState } from "react";

type Stage = "credentials" | "mfa" | "complete";

export function IdentitySignIn() {
  const [stage, setStage] = useState<Stage>("credentials");

  return (
    <section className="identity-auth-layout" aria-labelledby="sign-in-title">
      <div className="identity-auth-intro">
        <span className="eyebrow">SECURE GOVERNMENT ACCESS</span>
        <h1 id="sign-in-title">Sign in to the fleet control platform</h1>
        <p>
          Protected access for authorized Oromia Government personnel. Activity
          is recorded for accountability and security review.
        </p>
        <ul className="trust-list" aria-label="Security safeguards">
          <li>Multi-factor authentication</li>
          <li>Organization-scoped authority</li>
          <li>Audited privileged actions</li>
        </ul>
      </div>
      <form
        className="identity-auth-card"
        onSubmit={(event) => {
          event.preventDefault();
          setStage(stage === "credentials" ? "mfa" : "complete");
        }}
      >
        {stage === "credentials" && (
          <>
            <div>
              <span className="step-label">Step 1 of 2</span>
              <h2>Verify your account</h2>
              <p>Use your approved employee identifier or work email.</p>
            </div>
            <label>
              Employee identifier or email
              <input name="identifier" autoComplete="username" required />
            </label>
            <label>
              Password
              <input
                name="password"
                type="password"
                autoComplete="current-password"
                required
              />
            </label>
            <button className="primary-button" type="submit">
              Continue securely
            </button>
            <a href="#password-help">Forgot your password?</a>
          </>
        )}
        {stage === "mfa" && (
          <>
            <div>
              <span className="step-label">Step 2 of 2</span>
              <h2>Confirm it is you</h2>
              <p>Enter the six-digit code from your authenticator.</p>
            </div>
            <label>
              Verification code
              <input
                name="code"
                inputMode="numeric"
                autoComplete="one-time-code"
                pattern="[0-9]{6}"
                maxLength={6}
                required
              />
            </label>
            <label className="checkbox-field">
              <input name="trusted" type="checkbox" />
              Trust this secured device for the approved period
            </label>
            <button className="primary-button" type="submit">
              Verify and sign in
            </button>
            <button
              className="text-button"
              type="button"
              onClick={() => setStage("credentials")}
            >
              Back to account details
            </button>
          </>
        )}
        {stage === "complete" && (
          <div className="auth-success" role="status">
            <span aria-hidden="true">✓</span>
            <h2>Identity verified</h2>
            <p>Your secure workspace is being prepared.</p>
          </div>
        )}
      </form>
    </section>
  );
}
