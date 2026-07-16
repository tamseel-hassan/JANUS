import type { Metadata } from "next";
import localFont from "next/font/local";
import "./globals.css";
import ClientLayout from "@/components/ClientLayout";

const sora = localFont({
  src: [
    {
      path: "../fonts/Sora/Sora-Thin.otf",
      weight: "100",
      style: "normal",
    },
    {
      path: "../fonts/Sora/Sora-ThinItalic.otf",
      weight: "100",
      style: "italic",
    },
    {
      path: "../fonts/Sora/Sora-ExtraLight.otf",
      weight: "200",
      style: "normal",
    },
    {
      path: "../fonts/Sora/Sora-ExtraLightItalic.otf",
      weight: "200",
      style: "italic",
    },
    {
      path: "../fonts/Sora/Sora-Light.otf",
      weight: "300",
      style: "normal",
    },
    {
      path: "../fonts/Sora/Sora-LightItalic.otf",
      weight: "300",
      style: "italic",
    },
    {
      path: "../fonts/Sora/Sora-Regular.otf",
      weight: "400",
      style: "normal",
    },
    {
      path: "../fonts/Sora/Sora-Italic.otf",
      weight: "400",
      style: "italic",
    },
    {
      path: "../fonts/Sora/Sora-Medium.otf",
      weight: "500",
      style: "normal",
    },
    {
      path: "../fonts/Sora/Sora-MediumItalic.otf",
      weight: "500",
      style: "italic",
    },
    {
      path: "../fonts/Sora/Sora-SemiBold.otf",
      weight: "600",
      style: "normal",
    },
    {
      path: "../fonts/Sora/Sora-SemiBoldItalic.otf",
      weight: "600",
      style: "italic",
    },
    {
      path: "../fonts/Sora/Sora-Bold.otf",
      weight: "700",
      style: "normal",
    },
    {
      path: "../fonts/Sora/Sora-BoldItalic.otf",
      weight: "700",
      style: "italic",
    },
    {
      path: "../fonts/Sora/Sora-ExtraBold.otf",
      weight: "800",
      style: "normal",
    },
    {
      path: "../fonts/Sora/Sora-ExtraBoldItalic.otf",
      weight: "800",
      style: "italic",
    },
  ],
  variable: "--font-sora",
});

export const metadata: Metadata = {
  title: "Janus",
  description: "Network Management System",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      suppressHydrationWarning
    >
      <head>
        <script dangerouslySetInnerHTML={{ __html: `
          try {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'light') {
              document.documentElement.classList.remove('dark');
            } else {
              document.documentElement.classList.add('dark');
            }
          } catch (_) {}
        ` }} />
      </head>
      <body className={`${sora.variable} font-sans min-h-full flex flex-col antialiased`}>
        <ClientLayout>{children}</ClientLayout>
      </body>
    </html>
  );
}
