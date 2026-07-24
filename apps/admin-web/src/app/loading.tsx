import { translate } from "@oromia/localization";

export default function Loading() {
  return (
    <div className="state-box" aria-live="polite">
      {translate("en", "state.loading")}…
    </div>
  );
}
