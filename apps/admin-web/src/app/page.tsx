import { translate } from "@oromia/localization";
import { getServerLocale } from "@/localization/server-locale";

export default async function Home() {
  const locale = await getServerLocale();

  return (
    <section className="panel" aria-labelledby="foundation-title">
      <p className="eyebrow">{translate(locale, "app.name")}</p>
      <h1 id="foundation-title">{translate(locale, "dashboard.title")}</h1>
      <p>{translate(locale, "dashboard.description")}</p>
      <div className="empty-state">
        <strong>{translate(locale, "state.empty")}</strong>
      </div>
    </section>
  );
}
