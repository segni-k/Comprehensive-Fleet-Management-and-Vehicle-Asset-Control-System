export const organizationPermissions = {
  typeView: "organization.type.view",
  typeCreate: "organization.type.create",
  typeUpdate: "organization.type.update",
  typeActivate: "organization.type.activate",
  typeDeactivate: "organization.type.deactivate",
  nodeView: "organization.node.view",
  nodeCreate: "organization.node.create",
  nodeUpdate: "organization.node.update",
  nodeActivate: "organization.node.activate",
  nodeDeactivate: "organization.node.deactivate",
  hierarchyView: "organization.hierarchy.view",
  hierarchyPreview: "organization.hierarchy.preview",
  moveRequest: "organization.hierarchy.move.request",
  moveApprove: "organization.hierarchy.move.approve",
  moveReject: "organization.hierarchy.move.reject",
  moveApply: "organization.hierarchy.move.apply",
  hierarchyHistoryView: "organization.hierarchy.history.view",
  contactManage: "organization.contact.manage",
  managerManage: "organization.manager.manage",
  settingsView: "organization.settings.view",
  settingsManage: "organization.settings.manage",
} as const;

export type OrganizationPermission =
  (typeof organizationPermissions)[keyof typeof organizationPermissions];
