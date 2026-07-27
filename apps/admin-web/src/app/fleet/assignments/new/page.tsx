import { FleetRecordForm } from "@/components/fleet/FleetRecordForm";
import { getServerLocale } from "@/localization/server-locale";

export default async function NewAssignmentPage({
  searchParams,
}: {
  readonly searchParams: Promise<{ organization_id?: string }>;
}) {
  const { organization_id: organizationId } = await searchParams;
  return (
    <FleetRecordForm
      locale={await getServerLocale()}
      mode="assignment"
      {...(organizationId ? { organizationId } : {})}
    />
  );
}
