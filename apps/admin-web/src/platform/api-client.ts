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
  if (init.body instanceof FormData) {
    for (const value of init.body.values()) {
      if (typeof value === "string" && /^[0-9A-HJKMNP-TV-Z]{26}$/.test(value))
        ulids.add(value);
    }
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
      "fleet.reference.view",
      "fleet.reference.manage",
      "fleet.dashboard.view",
      "vehicle.view",
      "vehicle.create",
      "vehicle.status.manage",
      "vehicle.transfer",
      "vehicle.odometer.record",
      "vehicle.plate.manage",
      "vehicle.fleet.assign",
      "vehicle.compliance.manage",
      "driver.view",
      "driver.create",
      "driver.status.manage",
      "driver.licence.manage",
      "assignment.view",
      "assignment.create",
      "assignment.close",
      "geography.dashboard.view",
      "geography.reference.view",
      "geography.reference.manage",
      "place.view",
      "place.manage",
      "place.approve",
      "place.hierarchy.manage",
      "place.policy.manage",
      "place.policy.approve",
      "route.view",
      "route.manage",
      "route.approve",
      "distance.view",
      "distance.manage",
      "distance.approve",
      "geography.zone.view",
      "geography.zone.manage",
      "geography.import.manage",
      "geography.import.approve",
      "document.view",
      "document.upload",
    ].join(","),
    "X-Organization-Scope": [...ulids].join(","),
  };
}

function requiredApiBaseUrl(): string {
  const value = process.env.NEXT_PUBLIC_API_BASE_URL;
  if (!value) throw new Error("NEXT_PUBLIC_API_BASE_URL is required");
  return value.replace(/\/$/, "");
}
