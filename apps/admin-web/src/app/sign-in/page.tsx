import { StatePanel } from "@/components/StatePanel";
import { getServerLocale } from "@/localization/server-locale";

export default async function SignInPage() {
  return (
    <StatePanel
      locale={await getServerLocale()}
      title="auth.integrationPending"
    />
  );
}
