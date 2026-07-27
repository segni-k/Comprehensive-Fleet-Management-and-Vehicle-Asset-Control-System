import { render, screen, waitFor } from "@testing-library/react";
import { vi } from "vitest";
import { DriverProfile } from "../DriverProfile";
import { FleetWorkspace } from "../FleetWorkspace";
import { apiRequest } from "@/platform/api-client";

vi.mock("@/platform/api-client", async () => {
  const actual = await vi.importActual<typeof import("@/platform/api-client")>(
    "@/platform/api-client",
  );
  return { ...actual, apiRequest: vi.fn() };
});

const request = vi.mocked(apiRequest);

describe("FleetWorkspace", () => {
  beforeEach(() => {
    request.mockReset();
  });

  it("requires an explicit organization context without exposing aggregate data", () => {
    render(<FleetWorkspace locale="en" />);

    expect(
      screen.getByRole("heading", { name: "Fleet command registry" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/No cross-organization totals/),
    ).toBeInTheDocument();
    expect(request).not.toHaveBeenCalled();
  });

  it("renders the server-authoritative asset passport and operational metrics", async () => {
    request
      .mockResolvedValueOnce({
        data: {
          vehicles: {
            total: 1,
            active: 1,
            unavailable: 0,
            retired: 0,
            unassigned: 0,
          },
          drivers: {
            total: 1,
            available: 0,
            assigned: 1,
            licences_expiring_30_days: 0,
          },
          compliance: { expiring_30_days: 0, expired: 0 },
          assignments: { active: 1, awaiting_acknowledgement: 1 },
          generated_at: "2026-07-26T00:00:00Z",
        },
      })
      .mockResolvedValueOnce({
        data: [
          {
            id: "01K00000000000000000000001",
            asset_number: "AST-001",
            plate_number: "OR-2-10001",
            manufacturer: { name: "Toyota" },
            model: { name: "Land Cruiser" },
            model_year: 2025,
            current_odometer_km: "1240.0",
            ownership_type: "owned",
            status: "active",
            record_version: 2,
          },
        ],
      })
      .mockResolvedValueOnce({ data: [] })
      .mockResolvedValueOnce({ data: [] });

    render(
      <FleetWorkspace
        locale="en"
        organizationId="01K00000000000000000000002"
      />,
    );

    await waitFor(() =>
      expect(screen.getByText("OR-2-10001")).toBeInTheDocument(),
    );
    expect(screen.getByText("AST-001")).toBeInTheDocument();
    expect(
      screen.getByText("Toyota · Land Cruiser · 2025"),
    ).toBeInTheDocument();
    expect(screen.getByText("Server-authoritative")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /Open vehicle AST-001/ }),
    ).toHaveAttribute(
      "href",
      "/fleet/vehicles/01K00000000000000000000001?organization_id=01K00000000000000000000002",
    );
  });

  it("renders a protected driver profile without licence or contact secrets", async () => {
    request.mockResolvedValueOnce({
      data: {
        id: "01K00000000000000000000005",
        organization_id: "01K00000000000000000000002",
        employee_number: "DRV-001",
        full_name: "Amina Gemechu",
        employment_status: "active",
        status: "active",
        availability_status: "available",
        hired_on: "2025-01-01",
        terminated_on: null,
        record_version: 1,
        licences: [
          {
            id: "01K00000000000000000000006",
            issuing_authority: "Transport Authority",
            issued_on: "2025-01-01",
            expires_on: "2027-01-01",
            status: "verified",
            classes: [{ id: "01K00000000000000000000007", code: "B" }],
          },
        ],
        assignments: [],
      },
      history: {
        statuses: [
          {
            id: "01K00000000000000000000008",
            to_status: "active",
            availability_status: "available",
            reason: "Initial driver registry entry.",
            effective_at: "2026-07-26T08:00:00Z",
          },
        ],
        qualifications: [],
        restrictions: [],
        documents: [
          {
            id: "01K00000000000000000000009",
            document_type: "DRIVER_LICENCE",
            category: "licence",
            classification: "restricted",
            status: "trusted",
            expires_at: null,
            record_version: 1,
            original_filename: "licence-front.pdf",
            media_type: "application/pdf",
            size_bytes: 1000,
            scan_status: "clean",
            trust_status: "trusted",
            created_at: "2026-07-26T08:00:00Z",
          },
        ],
      },
    });

    render(
      <DriverProfile
        driverId="01K00000000000000000000005"
        locale="en"
        organizationId="01K00000000000000000000002"
      />,
    );

    expect(
      await screen.findByRole("heading", { name: "Amina Gemechu" }),
    ).toBeInTheDocument();
    expect(screen.getByText("licence-front.pdf")).toBeInTheDocument();
    expect(screen.queryByText(/LIC-SECRET/)).not.toBeInTheDocument();
  });
});
