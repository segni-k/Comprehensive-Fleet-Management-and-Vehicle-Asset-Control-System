import { OrganizationEditor } from "@/components/organization/OrganizationEditor";
import { getServerLocale } from "@/localization/server-locale";

export default async function NewOrganizationTypePage() {
  return (
    <OrganizationEditor locale={await getServerLocale()} resource="type" />
  );
}
