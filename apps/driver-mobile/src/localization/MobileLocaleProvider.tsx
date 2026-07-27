import {
  createContext,
  useContext,
  useMemo,
  useState,
  type PropsWithChildren,
} from "react";
import type { Locale } from "@oromia/localization";

interface MobileLocaleContextValue {
  readonly locale: Locale;
  readonly setLocale: (locale: Locale) => void;
}

const MobileLocaleContext = createContext<MobileLocaleContextValue>({
  locale: "en",
  setLocale: () => undefined,
});

export function MobileLocaleProvider({ children }: PropsWithChildren) {
  const [locale, setLocale] = useState<Locale>("en");
  const value = useMemo(() => ({ locale, setLocale }), [locale]);

  return (
    <MobileLocaleContext.Provider value={value}>
      {children}
    </MobileLocaleContext.Provider>
  );
}

export function useMobileLocale(): MobileLocaleContextValue {
  return useContext(MobileLocaleContext);
}
