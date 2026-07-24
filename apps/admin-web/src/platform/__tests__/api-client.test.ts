import { ApiProblem, apiRequest } from "../api-client";

describe("apiRequest", () => {
  it("maps problem details without logging sensitive content", async () => {
    process.env.NEXT_PUBLIC_API_BASE_URL = "https://example.test/api/v1";
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({
        ok: false,
        json: async () => ({
          type: "https://example.test/problems/forbidden",
          title: "Forbidden",
          status: 403,
          code: "AUTHORIZATION_DENIED",
          correlation_id: "123",
        }),
      }),
    );

    await expect(apiRequest("/protected")).rejects.toBeInstanceOf(ApiProblem);
  });
});
