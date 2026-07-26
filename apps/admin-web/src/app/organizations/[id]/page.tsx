import { getServerLocale } from "@/localization/server-locale";
import { OrganizationDetailConsole } from "@/components/organization/OrganizationDetailConsole";

export default async function OrganizationDetailPage({
  params,
}: {
  readonly params: Promise<{ id: string }>;
}) {
  const [{ id }, locale] = await Promise.all([params, getServerLocale()]);

  return <OrganizationDetailConsole id={id} locale={locale} />;
}
