import { getServerLocale } from "@/localization/server-locale";
import { OrganizationWorkspace } from "@/components/organization/OrganizationWorkspace";
import { milestone2DevelopmentPermissions } from "@/organization/development-authorization";

export default async function OrganizationsPage() {
  const locale = await getServerLocale();

  return (
    <OrganizationWorkspace
      locale={locale}
      permissions={milestone2DevelopmentPermissions()}
    />
  );
}
