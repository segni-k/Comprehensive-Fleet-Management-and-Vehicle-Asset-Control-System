import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { OrganizationWorkspace } from "../OrganizationWorkspace";
import { organizationPermissions } from "@/organization/permissions";
import { apiRequest } from "@/platform/api-client";

vi.mock("@/platform/api-client", async () => {
  const actual = await vi.importActual<typeof import("@/platform/api-client")>(
    "@/platform/api-client",
  );
  return { ...actual, apiRequest: vi.fn() };
});

const mockedApiRequest = vi.mocked(apiRequest);
const organization = {
  id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
  type_id: "01ARZ3NDEKTSV4RRFFQ69G5FAW",
  code: "FICTIONAL",
  name: {
    en: "Fictional Test Office",
    om: "[review]",
    am: "[review]",
  },
  status: "active",
  record_version: 1,
};

describe("OrganizationWorkspace", () => {
  beforeEach(() => {
    mockedApiRequest.mockReset();
  });

  it("renders loading, hierarchy tree, and permission-aware actions", async () => {
    mockedApiRequest
      .mockResolvedValueOnce({
        data: [
          {
            id: "01ARZ3NDEKTSV4RRFFQ69G5FAX",
            code: "TEST",
            name_key: "organization.type.test",
            description: "Fictional type",
            status: "active",
            configuration_status: "approved",
            sort_order: 1,
            may_be_root: true,
            record_version: 1,
          },
        ],
        meta: { correlation_id: crypto.randomUUID() },
      })
      .mockResolvedValueOnce({
        data: [organization],
        meta: { correlation_id: crypto.randomUUID() },
      })
      .mockResolvedValueOnce({
        data: [{ ...organization, children: [] }],
        meta: { correlation_id: crypto.randomUUID() },
      });

    render(
      <OrganizationWorkspace
        locale="en"
        permissions={Object.values(organizationPermissions)}
      />,
    );
    expect(screen.getByRole("status")).toHaveTextContent("Loading");
    expect(
      await screen.findByRole("heading", { name: "Organization hierarchy" }),
    ).toBeInTheDocument();
    expect(screen.getAllByText("Fictional Test Office")).not.toHaveLength(0);
    expect(
      screen.getByRole("link", { name: "Create organization type" }),
    ).toBeInTheDocument();
  });

  it("renders forbidden state without making an API request", () => {
    render(<OrganizationWorkspace locale="en" permissions={[]} />);

    expect(screen.getByRole("alert")).toHaveTextContent(
      "You do not have permission",
    );
    expect(mockedApiRequest).not.toHaveBeenCalled();
  });

  it("renders empty states accessibly", async () => {
    mockedApiRequest.mockResolvedValue({
      data: [],
      meta: { correlation_id: crypto.randomUUID() },
    });

    render(
      <OrganizationWorkspace
        locale="am"
        permissions={[organizationPermissions.hierarchyView]}
      />,
    );

    expect(await screen.findAllByText(/የመድረክ መረጃ የለም/)).not.toHaveLength(0);
  });

  it("submits a move preview and displays impact warnings", async () => {
    mockedApiRequest
      .mockResolvedValueOnce({ data: [], meta: { correlation_id: "one" } })
      .mockResolvedValueOnce({
        data: [organization],
        meta: { correlation_id: "two" },
      })
      .mockResolvedValueOnce({
        data: [{ ...organization, children: [] }],
        meta: { correlation_id: "three" },
      })
      .mockResolvedValueOnce({
        data: {
          id: "01ARZ3NDEKTSV4RRFFQ69G5FAY",
          current_parent_id: null,
          proposed_parent_id: organization.id,
          affected_descendants: [],
          affected_manager_assignments: 0,
          affected_role_assignments: [],
          affected_records_by_category: {},
          warnings: ["PERMISSION_EXPANSION_REVIEW_REQUIRED"],
          blockers: [],
          workflow_impact: { requires_reauthorization: true },
          configuration_impact: { inherited_settings_may_change: true },
          preview_version: 1,
          requested_effective_at: "2026-08-01T00:00:00Z",
          expires_at: "2026-08-01T00:00:00Z",
        },
        meta: { correlation_id: "four" },
      });

    render(
      <OrganizationWorkspace
        locale="en"
        permissions={[
          organizationPermissions.hierarchyView,
          organizationPermissions.hierarchyPreview,
        ]}
      />,
    );
    await screen.findByRole("heading", { name: "Hierarchy change preview" });
    const form = screen
      .getByRole("button", { name: "Preview" })
      .closest("form");
    expect(form).not.toBeNull();
    fireEvent.change(screen.getByLabelText("Organization details"), {
      target: { value: organization.id },
    });
    fireEvent.change(screen.getByLabelText("Proposed parent"), {
      target: { value: organization.id },
    });
    fireEvent.change(screen.getByLabelText("Effective date"), {
      target: { value: "2026-08-01T00:00" },
    });
    fireEvent.change(screen.getByLabelText("Reason"), {
      target: { value: "Controlled test preview" },
    });
    fireEvent.submit(form!);

    await waitFor(() =>
      expect(
        screen.getByText("PERMISSION_EXPANSION_REVIEW_REQUIRED"),
      ).toBeInTheDocument(),
    );
  });
});
