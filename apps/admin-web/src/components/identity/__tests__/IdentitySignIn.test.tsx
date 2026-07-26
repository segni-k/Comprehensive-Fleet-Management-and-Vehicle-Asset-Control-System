import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { IdentitySignIn } from "../IdentitySignIn";

describe("IdentitySignIn", () => {
  it("provides an accessible credential and MFA flow", () => {
    render(<IdentitySignIn />);

    expect(screen.getByLabelText(/employee identifier/i)).toHaveAttribute(
      "autocomplete",
      "username",
    );
    fireEvent.change(screen.getByLabelText(/employee identifier/i), {
      target: { value: "driver@example.test" },
    });
    fireEvent.change(screen.getByLabelText("Password"), {
      target: { value: "Fleet!Secure123" },
    });
    fireEvent.click(screen.getByRole("button", { name: /continue securely/i }));
    expect(
      screen.getByRole("heading", { name: /confirm it is you/i }),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/verification code/i)).toHaveAttribute(
      "autocomplete",
      "one-time-code",
    );
  });
});
