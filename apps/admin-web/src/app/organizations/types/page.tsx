import { getServerLocale } from "@/localization/server-locale";
import { OrganizationTypesConsole } from "@/components/organization/OrganizationTypesConsole";

export default async function OrganizationTypesPage() {
  const locale = await getServerLocale();

  return <OrganizationTypesConsole locale={locale} />;
}
