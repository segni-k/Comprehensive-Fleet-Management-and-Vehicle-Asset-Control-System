import { FleetWorkspace } from "@/components/fleet/FleetWorkspace";
import { getServerLocale } from "@/localization/server-locale";

export default async function FleetPage({
  searchParams,
}: {
  readonly searchParams: Promise<{ organization_id?: string }>;
}) {
  const { organization_id: organizationId } = await searchParams;

  return (
    <FleetWorkspace
      locale={await getServerLocale()}
      {...(organizationId ? { organizationId } : {})}
    />
  );
}
