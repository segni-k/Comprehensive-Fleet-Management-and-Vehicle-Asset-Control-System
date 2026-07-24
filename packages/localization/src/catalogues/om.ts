import type { en } from "./en";

// Human review required before production.
export const om: Record<keyof typeof en, string> = {
  "app.name": "Bulchiinsa Konkolaataa Oromiyaa",
  "nav.home": "Mana",
  "nav.profile": "Piroofaayilii",
  "nav.language": "Afaan",
  "nav.skip": "[HUMAN_REVIEW] Gara qabiyyee ijoo darbi",
  "auth.signIn": "Seeni",
  "auth.integrationPending":
    "[HUMAN_REVIEW] Mirkaneessuun Milestone 3 keessatti wal qabata.",
  "dashboard.title": "Bu'uura pilaatfoormii",
  "dashboard.description":
    "[HUMAN_REVIEW] Daashboordiin hojii yeroo murtaa'e keessatti dabalama.",
  "state.loading": "Fe'amaa jira",
  "state.empty": "Odeeffannoon pilaatfoormii hin jiru.",
  "state.unauthorized": "Seenuun barbaachisa.",
  "state.forbidden": "Fuula kana ilaaluuf hayyama hin qabdu.",
  "state.notFound": "Fuulli barbaadame hin argamne.",
  "state.unavailable": "Tajaajilli yeroo muraasaaf hin jiru.",
  "state.serviceUnavailable": "[HUMAN_REVIEW] Tajaajilli hin jiru",
  "state.offline": "Sarar-malee",
  "state.online": "Sarararra",
  "state.forcedUpdate": "[HUMAN_REVIEW] Haaromsi appii mirkanaa'e barbaachisa.",
  "state.revokedDevice": "[HUMAN_REVIEW] Meeshaan kun hayyama hin qabu.",
  "state.enrollmentRequired": "[HUMAN_REVIEW] Galmeen meeshaa barbaachisa.",
  "sync.title": "Haala walsimsiisaa",
  "sync.noPending": "Wanti eegaa jiru hin jiru.",
  "support.title": "Deeggarsa",
  "support.reference": "[HUMAN_REVIEW] Yeroo deeggarsa gaafattu wabii kenni.",
  "profile.title": "Piroofaayilii",
  "language.en": "English",
  "language.om": "Afaan Oromoo",
  "language.am": "አማርኛ",
};
