import { StatePanel } from "@/components/StatePanel";
import { getServerLocale } from "@/localization/server-locale";

export default async function NotFound() {
  return <StatePanel locale={await getServerLocale()} title="state.notFound" />;
}
