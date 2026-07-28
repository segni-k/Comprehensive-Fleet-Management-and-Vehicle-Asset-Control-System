import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiRequest } from "@/platform/api-client";
import { FuelWorkspace } from "../FuelWorkspace";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/platform/api-client", async () => {
  const actual = await vi.importActual<typeof import("@/platform/api-client")>("@/platform/api-client");
  return { ...actual, apiRequest: vi.fn() };
});
const request = vi.mocked(apiRequest);

describe("FuelWorkspace", () => {
  beforeEach(() => request.mockReset());

  it("requires explicit organization context and offers an active selector", async () => {
    request.mockResolvedValueOnce({ data: [{ id: "01K00000000000000000000001", code: "OFB", name: { en: "Oromia Finance Bureau" } }] });
    render(<FuelWorkspace locale="en" />);
    expect(screen.getByRole("heading", { name: "Organization context" })).toBeInTheDocument();
    expect(await screen.findByRole("option", { name: /OFB/ })).toBeInTheDocument();
  });

  it("renders organization-scoped transactions, exception metrics and evidence links", async () => {
    request
      .mockResolvedValueOnce({ data: [] })
      .mockResolvedValueOnce({ data: [], current_page: 1, last_page: 1, total: 0 })
      .mockResolvedValueOnce({ data: [{ id: "01K00000000000000000000011", transaction_number: "FT-001", trip_id: "01K00000000000000000000012", vehicle_id: "01K00000000000000000000013", driver_id: "01K00000000000000000000014", quantity_litres: "20.000", unit_price: "60.0000", total_amount: "1200.00", receipt_number: "R-001", duplicate_indicators: ["DUPLICATE_RECEIPT_NUMBER"], status: "review_required", transacted_at: "2026-07-28T10:00:00Z", record_version: 1 }], current_page: 1, last_page: 1, total: 1 })
      .mockResolvedValueOnce({ data: [], current_page: 1, last_page: 1, total: 0 })
      .mockResolvedValueOnce({ data: { fuel_types: [], stations: [], price_references: [], vehicle_profiles: [] } });
    render(<FuelWorkspace locale="en" organizationId="01K00000000000000000000001" />);
    expect(await screen.findByText("FT-001")).toBeInTheDocument();
    expect(screen.getByText("Needs review")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Open record/ })).toHaveAttribute("href", "/fuel/transactions/01K00000000000000000000011?organization_id=01K00000000000000000000001");
  });
});
