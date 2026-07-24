import { StatePanel } from "@/components/StatePanel";
import { getServerLocale } from "@/localization/server-locale";

export default async function ProfilePage() {
  return <StatePanel locale={await getServerLocale()} title="profile.title" />;
}
