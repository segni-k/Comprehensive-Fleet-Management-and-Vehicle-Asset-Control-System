import { GeographyWorkspace } from "@/components/geography/GeographyWorkspace";
import { getServerLocale } from "@/localization/server-locale";

export default async function GeographyPage({
  searchParams,
}: {
  readonly searchParams: Promise<{ organization_id?: string }>;
}) {
  const { organization_id: organizationId } = await searchParams;

  return (
    <GeographyWorkspace
      locale={await getServerLocale()}
      {...(organizationId ? { organizationId } : {})}
    />
  );
}
