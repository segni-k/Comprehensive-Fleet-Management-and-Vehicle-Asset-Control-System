import { act, fireEvent, render, screen } from "@testing-library/react-native";
import App from "../app/index";
import ApprovalScreen from "../app/approvals";
import NotificationsScreen from "../app/notifications";
import type {
  DriverAssignmentCache,
  DriverAssignmentDataSource,
  DriverAssignmentSnapshot,
} from "../assignments/types";
import { DriverVehicleWorkspace } from "../components/DriverVehicleWorkspace";
import { OperationalGeographyWorkspace } from "../components/OperationalGeographyWorkspace";
import type {
  OperationalGeographyCache,
  OperationalGeographyDataSource,
  OperationalGeographySnapshot,
} from "../geography/types";
import { PlatformStateCard } from "../components/PlatformStateCard";
import { resolveMobileLayoutDensity } from "../theme/tokens";

let mockNetworkListener:
  | ((state: { isConnected: boolean }) => void)
  | undefined;

jest.mock("expo-router", () => ({
  Link: ({ children }: { children: React.ReactNode }) => children,
}));

jest.mock("@react-native-community/netinfo", () => ({
  __esModule: true,
  default: {
    fetch: jest.fn(async () => ({ isConnected: true })),
    addEventListener: jest.fn(
      (listener: (state: { isConnected: boolean }) => void) => {
        mockNetworkListener = listener;
        return jest.fn();
      },
    ),
  },
}));

describe("NativeWind mobile foundation UI", () => {
  it("renders the representative screen and offline/sync states accessibly", () => {
    render(<App />);
    expect(screen.getByTestId("foundation-screen")).toBeOnTheScreen();
    expect(
      screen.getByRole("header", { name: "Oromia Fleet Management" }),
    ).toBeOnTheScreen();

    act(() => mockNetworkListener?.({ isConnected: false }));
    expect(screen.getByTestId("network-status")).toHaveTextContent("Offline");

    fireEvent.press(
      screen.getByRole("button", { name: "Synchronization status" }),
    );
    expect(screen.getByTestId("platform-state-sync")).toBeOnTheScreen();
  });

  it.each([
    [
      "English",
      "This operational message remains readable when translated content becomes substantially longer.",
    ],
    [
      "Afaan Oromoo",
      "Ergaan hojii kun yeroo hiikni isaa baayʼee dheeratu illee guutummaan isaa dubbifamuu qaba.",
    ],
    ["Amharic", "ይህ የአሠራር መልእክት ትርጉሙ በጣም ረጅም ቢሆንም ሙሉ በሙሉ ሊነበብ ይገባል።"],
  ])("does not clip long %s text", (_locale, longText) => {
    render(<PlatformStateCard state="support" translate={() => longText} />);
    for (const text of screen.getAllByText(longText)) {
      expect(text.props.numberOfLines).toBeUndefined();
      expect(text.props.allowFontScaling).not.toBe(false);
    }
  });

  it("selects responsive density for small, large, and font-scaled Android layouts", () => {
    expect(resolveMobileLayoutDensity(320, 1)).toBe("compact");
    expect(resolveMobileLayoutDensity(800, 1)).toBe("comfortable");
    expect(resolveMobileLayoutDensity(800, 1.5)).toBe("compact");
  });

  it("renders the offline notification center and filters read notices", () => {
    render(<NotificationsScreen />);
    expect(
      screen.getByRole("header", { name: "Notifications" }),
    ).toBeOnTheScreen();
    expect(screen.getByText(/Offline/)).toBeOnTheScreen();
    fireEvent.press(screen.getByRole("button", { name: "Unread" }));
    expect(
      screen.queryByText("Document verification completed"),
    ).not.toBeOnTheScreen();
    expect(
      screen.getByRole("button", {
        name: "A review task was assigned. Ready for review",
      }),
    ).toBeOnTheScreen();
  });

  it("requires a meaningful reason before enabling workflow decisions", () => {
    render(<ApprovalScreen />);
    const approve = screen.getByRole("button", { name: "Approve with reason" });
    expect(approve).toBeDisabled();
    fireEvent.changeText(screen.getByLabelText("Reason"), "Evidence checked");
    expect(approve).toBeEnabled();
    expect(screen.getByText("Decision history")).toBeOnTheScreen();
  });

  it("renders, caches, and acknowledges the driver's own assignment", async () => {
    let stored: DriverAssignmentSnapshot | null = null;
    const cache: DriverAssignmentCache = {
      initialize: jest.fn(async () => undefined),
      load: jest.fn(async () => stored),
      save: jest.fn(async (snapshot) => {
        stored = snapshot;
      }),
    };
    const assignment = {
      id: "01K00000000000000000000001",
      assignment_type: "permanent",
      starts_at: "2026-07-26T08:00:00Z",
      ends_at: null,
      acknowledgement_required: true,
      acknowledged_at: null,
      status: "active",
      record_version: 1,
      vehicle: {
        id: "01K00000000000000000000002",
        asset_number: "AST-001",
        plate_number: "OR-2-10001",
        status: "active",
        compliance: [
          {
            document_type: "insurance",
            expires_on: "2027-07-26",
            status: "current" as const,
          },
        ],
      },
    };
    const dataSource: DriverAssignmentDataSource = {
      list: jest.fn(async () => ({
        assignments: [assignment],
        synchronizedAt: "2026-07-26T08:05:00Z",
      })),
      acknowledge: jest.fn(async () => ({
        ...assignment,
        acknowledged_at: "2026-07-26T08:06:00Z",
        record_version: 2,
      })),
    };

    render(<DriverVehicleWorkspace cache={cache} dataSource={dataSource} />);

    expect(
      await screen.findByRole("header", { name: "OR-2-10001" }),
    ).toBeOnTheScreen();
    expect(screen.getByText("AST-001")).toBeOnTheScreen();
    expect(screen.getByText("Vehicle document status")).toBeOnTheScreen();
    expect(screen.getByText("Current")).toBeOnTheScreen();
    fireEvent.press(
      screen.getByRole("button", { name: "Acknowledge assignment" }),
    );
    expect(
      await screen.findByText("Assignment acknowledged"),
    ).toBeOnTheScreen();
    expect(dataSource.acknowledge).toHaveBeenCalledWith(
      assignment.id,
    );
    expect(cache.save).toHaveBeenCalled();
  });

  it("renders approved operational geography from encrypted offline-safe reference data", async () => {
    const snapshot: OperationalGeographySnapshot = {
      places: [
        {
          id: "01K00000000000000000000011",
          code: "ADAMA",
          name: { en: "Adama", om: "Adaamaa", am: "አዳማ" },
          place_category_id: "01K00000000000000000000012",
          latitude: "8.5400000",
          longitude: "39.2700000",
        },
        {
          id: "01K00000000000000000000013",
          code: "BISHOFTU",
          name: { en: "Bishoftu", om: "Bishooftuu", am: "ቢሾፍቱ" },
          place_category_id: "01K00000000000000000000012",
          latitude: "8.7500000",
          longitude: "38.9900000",
        },
      ],
      routes: [
        {
          id: "01K00000000000000000000014",
          code: "RTE-001",
          name: {
            en: "Approved service corridor",
            om: "Karaa tajaajilaa mirkanaaʼe",
            am: "የጸደቀ የአገልግሎት መስመር",
          },
          origin_place_id: "01K00000000000000000000011",
          destination_place_id: "01K00000000000000000000013",
          directional: true,
          versions: [
            {
              id: "01K00000000000000000000015",
              version: 1,
              alternative_label: "Primary",
              preferred: true,
              estimated_distance_km: "45.50",
              estimated_duration_minutes: 55,
              segments: [
                {
                  id: "01K00000000000000000000016",
                  sequence: 1,
                  origin_place_id: "01K00000000000000000000011",
                  destination_place_id: "01K00000000000000000000013",
                  distance_km: "45.50",
                  duration_minutes: 55,
                  mandatory_stop: true,
                },
              ],
            },
          ],
        },
      ],
      distanceLegs: [],
      synchronizedAt: "2026-07-27T08:00:00Z",
    };
    const cache: OperationalGeographyCache = {
      initialize: jest.fn(async () => undefined),
      load: jest.fn(async () => null),
      save: jest.fn(async () => undefined),
    };
    const dataSource: OperationalGeographyDataSource = {
      load: jest.fn(async () => snapshot),
    };

    render(
      <OperationalGeographyWorkspace cache={cache} dataSource={dataSource} />,
    );

    expect(
      await screen.findByRole("header", {
        name: "Approved service corridor",
      }),
    ).toBeOnTheScreen();
    expect(screen.getByText("45.50 km")).toBeOnTheScreen();
    expect(screen.getByText(/Mandatory reference stop/)).toBeOnTheScreen();
    expect(
      screen.getByText(/no background location or live tracking/i),
    ).toBeOnTheScreen();
    expect(cache.save).toHaveBeenCalledWith(snapshot);

    act(() => mockNetworkListener?.({ isConnected: false }));
    expect(screen.getByText(/Offline · encrypted reference/)).toBeOnTheScreen();
    fireEvent.press(screen.getByRole("tab", { name: "Approved places" }));
    expect(screen.getByRole("header", { name: "Adama" })).toBeOnTheScreen();
  });
});
