import { act, fireEvent, render, screen } from "@testing-library/react-native";
import App from "../../App";
import { PlatformStateCard } from "../components/PlatformStateCard";
import { resolveMobileLayoutDensity } from "../theme/tokens";

let mockNetworkListener:
  | ((state: { isConnected: boolean }) => void)
  | undefined;

jest.mock("@react-native-community/netinfo", () => ({
  __esModule: true,
  default: {
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
});
