import { OperationsControlRoom } from "@/components/operations/OperationsControlRoom";
import { getServerLocale } from "@/localization/server-locale";

export default async function OperationsPage() {
  return <OperationsControlRoom locale={await getServerLocale()} />;
}
