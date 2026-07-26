import { getServerLocale } from "@/localization/server-locale";
import { HierarchyMoveConsole } from "@/components/organization/HierarchyMoveConsole";

export default async function NewHierarchyMovePage() {
  const locale = await getServerLocale();

  return <HierarchyMoveConsole locale={locale} />;
}
