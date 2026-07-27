import { VehicleProfile } from "@/components/fleet/VehicleProfile";
import { getServerLocale } from "@/localization/server-locale";
import { notFound } from "next/navigation";

export default async function VehicleProfilePage({
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
    <VehicleProfile
      locale={await getServerLocale()}
      organizationId={organizationId}
      vehicleId={id}
    />
  );
}
