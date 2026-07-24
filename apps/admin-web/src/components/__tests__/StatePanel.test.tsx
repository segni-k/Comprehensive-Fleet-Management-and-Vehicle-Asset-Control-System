import { render, screen } from "@testing-library/react";
import { StatePanel } from "../StatePanel";

describe("StatePanel", () => {
  it("loads localized state and support reference", () => {
    render(
      <StatePanel
        locale="en"
        title="state.forbidden"
        supportReference="ABC-123"
      />,
    );
    expect(
      screen.getByRole("heading", {
        name: "You do not have permission to view this page.",
      }),
    ).toBeInTheDocument();
    expect(screen.getByText("ABC-123")).toBeInTheDocument();
  });
});
