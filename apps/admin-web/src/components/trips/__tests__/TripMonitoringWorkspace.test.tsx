import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { TripMonitoringWorkspace } from "../TripMonitoringWorkspace";
import { apiRequest } from "@/platform/api-client";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

vi.mock("@/platform/api-client", async () => {
  const actual = await vi.importActual<typeof import("@/platform/api-client")>(
    "@/platform/api-client",
  );
  return { ...actual, apiRequest: vi.fn() };
});

const request = vi.mocked(apiRequest);

describe("TripMonitoringWorkspace", () => {
  beforeEach(() => request.mockReset());

  it("requires an explicit organization scope and offers organization selection", async () => {
    request.mockResolvedValueOnce({
      data: [{
        id: "01K00000000000000000000001",
        code: "OFB",
        name: { en: "Oromia Finance Bureau" },
        status: "active",
      }],
    });
    render(<TripMonitoringWorkspace locale="en" />);
    expect(
      screen.getByRole("heading", { name: "Trip execution register" }),
    ).toBeInTheDocument();
    expect(await screen.findByRole("option", { name: /OFB/ })).toBeInTheDocument();
    expect(request).toHaveBeenCalledWith(
      "/organizations?filter%5Bstatus%5D=active&page_size=100",
    );
  });

  it("renders the organization-scoped active trip register", async () => {
    request.mockResolvedValueOnce({ data: [] }).mockResolvedValueOnce({
      data: [{
        id: "01K00000000000000000000041",
        trip_number: "TRP-0041",
        purpose: "Authorized field operation",
        status: "started",
        driver_id: "01K00000000000000000000042",
        vehicle_id: "01K00000000000000000000043",
        planned_departure_at: "2026-07-28T08:00:00Z",
        expected_return_at: "2026-07-28T18:00:00Z",
        record_version: 4,
      }],
      current_page: 1,
      last_page: 1,
      total: 1,
      per_page: 25,
      from: 1,
      to: 1,
      summary: {
        total: 1,
        active: 1,
        awaiting_action: 0,
        completed: 0,
        exceptions: 0,
        by_status: { started: 1 },
      },
    });
    render(
      <TripMonitoringWorkspace
        locale="en"
        organizationId="01K00000000000000000000001"
      />,
    );
    expect(await screen.findByText("TRP-0041")).toBeInTheDocument();
    expect(screen.getByText("Authorized field operation")).toBeInTheDocument();
    expect(screen.getByText("Active trips")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Open trip TRP-0041" }),
    ).toHaveAttribute(
      "href",
      "/trips/01K00000000000000000000041?organization_id=01K00000000000000000000001",
    );
  });
});
