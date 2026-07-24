import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { getServerLocale } from "@/localization/server-locale";
import "./globals.css";

export const metadata: Metadata = {
  title: "Oromia Fleet Management",
  description: "Administrative platform for the Oromia Finance Bureau",
};

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const locale = await getServerLocale();

  return (
    <html lang={locale}>
      <body>
        <AppShell locale={locale}>{children}</AppShell>
      </body>
    </html>
  );
}
