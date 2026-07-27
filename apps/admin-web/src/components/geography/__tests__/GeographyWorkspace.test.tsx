import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { GeographyWorkspace } from "../GeographyWorkspace";
import { apiRequest } from "@/platform/api-client";

vi.mock("@/platform/api-client", async () => {
  const actual = await vi.importActual<typeof import("@/platform/api-client")>(
    "@/platform/api-client",
  );
  return { ...actual, apiRequest: vi.fn() };
});

const request = vi.mocked(apiRequest);
const organizationId = "01K00000000000000000000001";

describe("GeographyWorkspace", () => {
  beforeEach(() => {
    request.mockReset();
  });

  it("requires explicit organization scope and supports long translated content", () => {
    render(<GeographyWorkspace locale="om" />);

    expect(
      screen.getByRole("heading", { name: "Ajaja teessuma hojii" }),
    ).toBeInTheDocument();
    expect(screen.getByText(/dhaabbata ceʼu aangoo ifaa/)).toBeInTheDocument();
    expect(request).not.toHaveBeenCalled();
  });

  it("renders authoritative place, route, distance, and zone registers", async () => {
    request
      .mockResolvedValueOnce({
        data: {
          places: { total: 1, active: 1, without_coordinates: 0, inactive: 0 },
          routes: { total: 1, active: 1, draft_versions: 0 },
          distance_references: { approved: 1, draft: 0, legs: 2 },
          operational_zones: 1,
          generated_at: "2026-07-27T00:00:00Z",
        },
      })
      .mockResolvedValueOnce({
        data: [
          {
            id: "01K00000000000000000000002",
            code: "CITY",
            name: { en: "City", om: "Magaalaa", am: "ከተማ" },
            classification: "administrative",
            requires_coordinates: true,
          },
        ],
      })
      .mockResolvedValueOnce({
        data: [
          {
            id: "01K00000000000000000000003",
            code: "ADAMA",
            name: { en: "Adama", om: "Adaamaa", am: "አዳማ" },
            category: {
              id: "01K00000000000000000000002",
              code: "CITY",
              name: { en: "City", om: "Magaalaa", am: "ከተማ" },
              classification: "administrative",
              requires_coordinates: true,
            },
            latitude: "8.5400000",
            longitude: "39.2700000",
            status: "active",
            record_version: 1,
          },
        ],
      })
      .mockResolvedValueOnce({
        data: [
          {
            id: "01K00000000000000000000004",
            code: "RTE-001",
            name: {
              en: "Primary service corridor",
              om: "Karaa tajaajilaa",
              am: "የአገልግሎት መስመር",
            },
            origin_place_id: "01K00000000000000000000003",
            destination_place_id: "01K00000000000000000000005",
            directional: true,
            status: "active",
            versions: [
              {
                id: "01K00000000000000000000006",
                version: 1,
                alternative_label: "Primary",
                preferred: true,
                estimated_distance_km: "92.50",
                estimated_duration_minutes: 110,
                status: "approved",
                record_version: 2,
              },
            ],
          },
        ],
      })
      .mockResolvedValueOnce({
        data: [
          {
            id: "01K00000000000000000000007",
            code: "DIST-2026",
            name: "Approved matrix",
            source_type: "bureau_matrix",
            status: "approved",
            record_version: 2,
            legs_count: 2,
          },
        ],
      })
      .mockResolvedValueOnce({
        data: [
          {
            id: "01K00000000000000000000008",
            code: "CENTRAL",
            name: { en: "Central zone", om: "Giddugala", am: "ማዕከላዊ" },
            zone_type: "service",
            status: "active",
          },
        ],
      })
      .mockResolvedValueOnce({
        data: [
          {
            id: "01K00000000000000000000009",
            import_type: "places",
            source_name: "governed-places.csv",
            source_checksum:
              "d7a8fbb307d7809469ca9abcb0082e4f8d5651e46d3cdb762d02d0bf37c9e592",
            row_count: 12,
            valid_row_count: 11,
            invalid_row_count: 1,
            status: "validation_failed",
            imported_by: "01K00000000000000000000010",
            approved_by: null,
            created_at: "2026-07-27T00:00:00Z",
          },
        ],
      });

    render(
      <GeographyWorkspace locale="en" organizationId={organizationId} />,
    );

    expect(await screen.findByText("Adama")).toBeInTheDocument();
    expect(screen.getByText("8.5400000")).toBeInTheDocument();
    expect(screen.getByText("2")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /Routes/ }));
    expect(screen.getByText("Primary service corridor")).toBeInTheDocument();
    expect(screen.getByText("92.50 km")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /Distance matrix/ }));
    expect(screen.getByText("Approved matrix")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /Operating zones/ }));
    expect(screen.getByText("Central zone")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /Controlled imports/ }));
    expect(screen.getByText("governed-places.csv")).toBeInTheDocument();
    expect(screen.getByText(/Invalid rows.*1/)).toBeInTheDocument();
    await waitFor(() => expect(request).toHaveBeenCalledTimes(7));
  });
});
