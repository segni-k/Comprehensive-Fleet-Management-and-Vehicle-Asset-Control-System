export interface AuthenticationContext {
  readonly state: "anonymous" | "authenticated" | "reauthentication_required";
  readonly subjectId?: string;
}

export interface AuthorizationBoundary {
  can(permission: string, resourceId?: string): Promise<boolean>;
}

export interface OrganizationContextBoundary {
  readonly activeOrganizationId?: string;
  changeOrganization(organizationId: string): Promise<void>;
}
