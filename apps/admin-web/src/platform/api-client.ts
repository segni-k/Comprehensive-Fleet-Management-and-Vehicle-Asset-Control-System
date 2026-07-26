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
  const developmentHeaders = developmentAuthorizationHeaders(path, init);
  const response = await fetch(`${requiredApiBaseUrl()}${path}`, {
    ...init,
    credentials: "include",
    headers: {
      Accept: "application/json",
      ...developmentHeaders,
      ...init.headers,
    },
  });

  if (!response.ok) {
    throw new ApiProblem((await response.json()) as ProblemDetails);
  }
  return response.json() as Promise<T>;
}

function developmentAuthorizationHeaders(
  path: string,
  init: RequestInit,
): Record<string, string> {
  if (process.env.NODE_ENV === "production") return {};

  const ulids = new Set(path.match(/[0-9A-HJKMNP-TV-Z]{26}/g) ?? []);
  if (typeof init.body === "string") {
    const bodyUlids = init.body.match(/[0-9A-HJKMNP-TV-Z]{26}/g) ?? [];
    bodyUlids.forEach((id) => ulids.add(id));
  }

  return {
    "X-Actor-Reference": "milestone-2-admin-web",
    "X-Permissions": [
      "organization.type.view",
      "organization.type.create",
      "organization.type.update",
      "organization.type.activate",
      "organization.type.deactivate",
      "organization.node.view",
      "organization.node.create",
      "organization.node.update",
      "organization.node.activate",
      "organization.node.deactivate",
      "organization.hierarchy.view",
      "organization.hierarchy.history.view",
      "organization.hierarchy.preview",
      "organization.hierarchy.move.request",
      "organization.hierarchy.move.approve",
      "organization.hierarchy.move.reject",
      "organization.hierarchy.move.apply",
      "organization.contact.manage",
      "organization.manager.manage",
      "organization.settings.view",
      "organization.settings.manage",
    ].join(","),
    "X-Organization-Scope": [...ulids].join(","),
  };
}

function requiredApiBaseUrl(): string {
  const value = process.env.NEXT_PUBLIC_API_BASE_URL;
  if (!value) throw new Error("NEXT_PUBLIC_API_BASE_URL is required");
  return value.replace(/\/$/, "");
}
