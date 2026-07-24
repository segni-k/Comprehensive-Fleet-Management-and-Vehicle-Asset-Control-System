import type { ProblemDetails } from "@oromia/shared-types";

export class ApiProblem extends Error {
  constructor(public readonly problem: ProblemDetails) {
    super(problem.title);
  }
}

export async function apiRequest<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const response = await fetch(`${requiredApiBaseUrl()}${path}`, {
    ...init,
    credentials: "include",
    headers: { Accept: "application/json", ...init.headers },
  });

  if (!response.ok) {
    throw new ApiProblem((await response.json()) as ProblemDetails);
  }
  return response.json() as Promise<T>;
}

function requiredApiBaseUrl(): string {
  const value = process.env.NEXT_PUBLIC_API_BASE_URL;
  if (!value) throw new Error("NEXT_PUBLIC_API_BASE_URL is required");
  return value.replace(/\/$/, "");
}
