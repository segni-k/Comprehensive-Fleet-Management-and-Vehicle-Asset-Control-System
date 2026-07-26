import { getServerLocale } from "@/localization/server-locale";
import { HierarchyHistoryConsole } from "@/components/organization/HierarchyHistoryConsole";

export default async function OrganizationHistoryPage() {
  const locale = await getServerLocale();

  return <HierarchyHistoryConsole locale={locale} />;
}
