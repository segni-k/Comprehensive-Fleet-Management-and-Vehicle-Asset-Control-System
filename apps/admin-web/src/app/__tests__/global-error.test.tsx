import { render, screen } from "@testing-library/react";
import GlobalError from "../global-error";

describe("global error boundary", () => {
  it("renders a safe support reference without exposing an error message", () => {
    render(
      <GlobalError
        error={Object.assign(new Error("secret"), { digest: "ref-123" })}
      />,
    );
    expect(screen.getByRole("alert")).toHaveTextContent("ref-123");
    expect(screen.queryByText("secret")).not.toBeInTheDocument();
  });
});
