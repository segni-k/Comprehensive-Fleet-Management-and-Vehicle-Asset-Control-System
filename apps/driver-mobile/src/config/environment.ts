const requiredPublicUrl = process.env.EXPO_PUBLIC_API_BASE_URL;

if (!requiredPublicUrl) {
  throw new Error("EXPO_PUBLIC_API_BASE_URL is required");
}

const parsedApiUrl = new URL(requiredPublicUrl);
if (!["http:", "https:"].includes(parsedApiUrl.protocol)) {
  throw new Error("EXPO_PUBLIC_API_BASE_URL must use HTTP or HTTPS");
}

export const environment = Object.freeze({
  apiBaseUrl: parsedApiUrl.toString().replace(/\/$/, ""),
  minimumAppVersion: process.env.EXPO_PUBLIC_MINIMUM_APP_VERSION ?? "0.1.0",
  supportReference:
    process.env.EXPO_PUBLIC_SUPPORT_REFERENCE ?? "OFB-FLEET-SUPPORT",
});
