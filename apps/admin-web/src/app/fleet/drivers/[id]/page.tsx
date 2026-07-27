import { DriverProfile } from "@/components/fleet/DriverProfile";
import { getServerLocale } from "@/localization/server-locale";
import { notFound } from "next/navigation";

export default async function DriverProfilePage({
  params,
  searchParams,
}: {
  readonly params: Promise<{ id: string }>;
  readonly searchParams: Promise<{ organization_id?: string }>;
}) {
  const [{ id }, { organization_id: organizationId }] = await Promise.all([
    params,
    searchParams,
  ]);
  if (!organizationId) notFound();

  return (
    <DriverProfile
      driverId={id}
      locale={await getServerLocale()}
      organizationId={organizationId}
    />
  );
}
