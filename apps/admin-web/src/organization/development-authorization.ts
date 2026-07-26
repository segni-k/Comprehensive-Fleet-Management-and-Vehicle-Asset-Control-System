import {
  organizationPermissions,
  type OrganizationPermission,
} from "./permissions";

export function milestone2DevelopmentPermissions(): readonly OrganizationPermission[] {
  if (process.env.NODE_ENV === "production") return [];

  return Object.values(organizationPermissions);
}
