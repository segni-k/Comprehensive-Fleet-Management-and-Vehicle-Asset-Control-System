/* eslint-disable @typescript-eslint/no-require-imports -- Jest 29 resetModules requires synchronous loading for module-initialization tests. */

describe("mobile environment validation", () => {
  const originalApiBaseUrl = process.env.EXPO_PUBLIC_API_BASE_URL;

  afterEach(() => {
    process.env.EXPO_PUBLIC_API_BASE_URL = originalApiBaseUrl;
    jest.resetModules();
  });

  it("rejects a missing API base URL", () => {
    delete process.env.EXPO_PUBLIC_API_BASE_URL;
    jest.resetModules();
    expect(() => require("../config/environment")).toThrow(
      "EXPO_PUBLIC_API_BASE_URL is required",
    );
  });

  it("normalizes an HTTP API base URL", () => {
    process.env.EXPO_PUBLIC_API_BASE_URL = "https://example.test/api/v1/";
    jest.resetModules();
    const loaded =
      require("../config/environment") as typeof import("../config/environment");
    const { environment } = loaded;
    expect(environment.apiBaseUrl).toBe("https://example.test/api/v1");
  });
});
