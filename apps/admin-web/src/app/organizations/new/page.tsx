import { OrganizationEditor } from "@/components/organization/OrganizationEditor";
import { getServerLocale } from "@/localization/server-locale";

export default async function NewOrganizationPage() {
  return (
    <OrganizationEditor locale={await getServerLocale()} resource="node" />
  );
}
