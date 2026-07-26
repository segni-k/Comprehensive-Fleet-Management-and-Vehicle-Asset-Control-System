import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiRequest } from "@/platform/api-client";
import { OrganizationTypesConsole } from "../OrganizationTypesConsole";
import { OrganizationDetailConsole } from "../OrganizationDetailConsole";
import { HierarchyMoveConsole } from "../HierarchyMoveConsole";

vi.mock("@/platform/api-client", async () => {
  const actual = await vi.importActual<typeof import("@/platform/api-client")>(
    "@/platform/api-client",
  );
  return { ...actual, apiRequest: vi.fn() };
});

const mockedApiRequest = vi.mocked(apiRequest);
const meta = { correlation_id: "test-correlation" };
const organizationId = "01ARZ3NDEKTSV4RRFFQ69G5FAV";

describe("Milestone 2 organization consoles", () => {
  beforeEach(() => {
    mockedApiRequest.mockReset();
  });

  it("renders the effective-dated type and rule register", async () => {
    mockedApiRequest
      .mockResolvedValueOnce({
        data: [
          {
            id: organizationId,
            code: "FICTIONAL",
            description: "Fictional type",
            name_key: "organization.type.fictional",
            status: "active",
            configuration_status: "approved",
            sort_order: 1,
            may_be_root: true,
            record_version: 1,
          },
        ],
        meta,
      })
      .mockResolvedValueOnce({ data: [], meta });

    render(<OrganizationTypesConsole locale="en" />);

    expect(await screen.findAllByText("FICTIONAL")).not.toHaveLength(0);
    expect(
      screen.getByRole("heading", { name: "Parent-child rules" }),
    ).toBeInTheDocument();
  });

  it("renders organization configuration and audit empty states", async () => {
    mockedApiRequest
      .mockResolvedValueOnce({
        data: {
          id: organizationId,
          type_id: organizationId,
          code: "FICTIONAL",
          name: { en: "Fictional Office", om: "Waajjira", am: "ቢሮ" },
          description: "Fictional test organization",
          status: "active",
          record_version: 1,
        },
        meta,
      })
      .mockResolvedValueOnce({ data: [], meta })
      .mockResolvedValueOnce({ data: [], meta })
      .mockResolvedValueOnce({ data: [], meta })
      .mockResolvedValueOnce({ data: [], meta })
      .mockResolvedValueOnce({ data: [], meta });

    render(<OrganizationDetailConsole id={organizationId} locale="en" />);

    expect(
      await screen.findByRole("heading", { name: "Fictional Office" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Contacts" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Hierarchy and audit history" }),
    ).toBeInTheDocument();
  });

  it("renders an accessible empty move register", async () => {
    mockedApiRequest.mockResolvedValueOnce({ data: [], meta });

    render(<HierarchyMoveConsole locale="en" />);

    expect(
      await screen.findByRole("heading", {
        name: "Scheduled hierarchy changes",
      }),
    ).toBeInTheDocument();
    expect(
      await screen.findByText("No platform information is available."),
    ).toBeInTheDocument();
  });
});
