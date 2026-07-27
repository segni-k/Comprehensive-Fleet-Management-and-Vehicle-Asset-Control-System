import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { OperationsControlRoom } from "../OperationsControlRoom";

describe("OperationsControlRoom", () => {
  it("supports keyboard-accessible register switching and filtered-empty recovery", () => {
    render(<OperationsControlRoom />);
    fireEvent.click(screen.getByRole("button", { name: /document trust/i }));
    expect(screen.getByText("Policy evidence · v3")).toBeInTheDocument();
    fireEvent.change(
      screen.getByRole("searchbox", { name: /search this register/i }),
      {
        target: { value: "no-result" },
      },
    );
    expect(screen.getByRole("status")).toHaveTextContent("No matching records");
    fireEvent.click(screen.getByRole("button", { name: /clear search/i }));
    expect(screen.getByText("Policy evidence · v3")).toBeInTheDocument();
  });
});
