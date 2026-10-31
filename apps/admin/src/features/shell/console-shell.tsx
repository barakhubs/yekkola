"use client";

import type { Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { cn } from "@yekkola/ui/lib/utils";
import {
  BookCheck,
  BookOpenText,
  GraduationCap,
  History,
  LayoutDashboard,
  LibraryBig,
  LogOut,
  ReceiptText,
  Settings,
  ShieldAlert,
  ShieldCheck,
  ShoppingCart,
  Users,
  Wallet,
  WifiOff,
} from "lucide-react";
import { useSearchParams } from "next/navigation";
import { useLocale, useTranslations } from "next-intl";
import { type ReactNode, useEffect } from "react";

import { useSession, useSignOut } from "@/features/auth/session";
import { Link, usePathname, useRouter } from "@/i18n/navigation";

/** Sidebar sections; each shows only with the permission its API routes require (PRD-09 FR-02). */
const NAV = [
  { href: "/", key: "home", icon: LayoutDashboard, permission: null },
  { href: "/applications", key: "applications", icon: GraduationCap, permission: "moderation.review" },
  { href: "/moderation/courses", key: "courseReviews", icon: BookCheck, permission: "moderation.review" },
  { href: "/courses", key: "courses", icon: LibraryBig, permission: "catalog.view" },
  { href: "/users", key: "users", icon: Users, permission: "users.view" },
  { href: "/orders", key: "orders", icon: ShoppingCart, permission: "commerce.view" },
  { href: "/finance/refunds", key: "refunds", icon: ReceiptText, permission: "finance.refunds" },
  { href: "/finance/payouts", key: "payouts", icon: Wallet, permission: "finance.payouts" },
  { href: "/finance/ledger", key: "ledger", icon: BookOpenText, permission: "finance.view" },
  { href: "/settings", key: "settings", icon: Settings, permission: "settings.manage" },
  { href: "/staff", key: "staff", icon: ShieldCheck, permission: "staff.manage" },
  { href: "/audit-log", key: "audit", icon: History, permission: "audit.view" },
] as const;

/**
 * Back-office frame (architecture §5.3): staff only — visitors go to sign-in and come back; signed-in non-staff
 * see why they can't continue. Sidebar on wide screens, a top row of links on narrow ones.
 */
export function ConsoleShell({ children }: { children: ReactNode }) {
  const t = useTranslations("admin");
  const common = useTranslations("common");
  const errors = useTranslations("errors");
  const session = useSession();
  const signOut = useSignOut();
  const router = useRouter();
  const pathname = usePathname();
  const query = useSearchParams().toString();

  useEffect(() => {
    if (session.isSignedOut) {
      const here = query ? `${pathname}?${query}` : pathname;
      router.replace(`/login?next=${encodeURIComponent(here)}`);
    }
  }, [session.isSignedOut, pathname, query, router]);

  if (session.isError) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState
          icon={<WifiOff />}
          title={errors("network")}
          action={
            <Button variant="outline" onClick={() => session.retry()}>
              {common("retry")}
            </Button>
          }
        />
      </div>
    );
  }

  if (!session.user) {
    return (
      <div className="mx-auto grid max-w-6xl gap-4 px-6 py-10" aria-busy>
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (!session.isStaff) {
    return (
      <div className="mx-auto max-w-xl px-4 py-16">
        <EmptyState
          icon={<ShieldAlert />}
          title={session.user.is_staff ? t("suspendedTitle") : t("notStaffTitle")}
          description={session.user.is_staff ? t("suspendedBody") : t("notStaffBody")}
          action={
            <Button variant="outline" onClick={() => signOut.mutate()} disabled={signOut.isPending}>
              {t("auth.signOut")}
            </Button>
          }
        />
      </div>
    );
  }

  const items = NAV.filter((item) => item.permission === null || session.can(item.permission));
  const isCurrent = (href: string) => (href === "/" ? pathname === "/" : pathname.startsWith(href));

  return (
    <div className="flex min-h-dvh flex-col lg:flex-row">
      <aside className="border-b bg-background lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-64 lg:shrink-0 lg:flex-col lg:border-r lg:border-b-0">
        <div className="flex h-14 items-center justify-between gap-2 px-4 lg:h-16">
          <Link href="/" className="font-extrabold tracking-wide text-primary">
            {t("brand")}
          </Link>
          <div className="flex items-center gap-1 lg:hidden">
            <LocaleLink />
            <SignOutButton onClick={() => signOut.mutate()} disabled={signOut.isPending} />
          </div>
        </div>
        <nav aria-label={t("nav.label")} className="overflow-x-auto px-2 pb-2 lg:flex-1 lg:px-3">
          <ul className="flex gap-1 lg:grid">
            {items.map(({ href, key, icon: Icon }) => (
              <li key={href}>
                <Link
                  href={href}
                  aria-current={isCurrent(href) ? "page" : undefined}
                  className={cn(
                    "flex items-center gap-2 rounded-md px-3 py-2 text-sm font-semibold whitespace-nowrap",
                    isCurrent(href)
                      ? "bg-accent text-primary"
                      : "text-muted-foreground hover:bg-muted hover:text-foreground",
                  )}
                >
                  <Icon aria-hidden className="size-4" />
                  {t(`nav.${key}`)}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
        <div className="hidden gap-2 border-t p-3 lg:grid">
          <p className="truncate text-sm font-semibold">{session.user.name ?? session.user.phone}</p>
          <div className="flex items-center gap-1">
            <LocaleLink />
            <SignOutButton onClick={() => signOut.mutate()} disabled={signOut.isPending} />
          </div>
        </div>
      </aside>
      <main id="content" className="min-w-0 flex-1 bg-muted/30">
        {children}
      </main>
    </div>
  );
}

function LocaleLink() {
  const locale = useLocale() as Locale;
  const pathname = usePathname();
  const query = Object.fromEntries(useSearchParams());
  const t = useTranslations("common.languages");
  const other: Locale = locale === "fr" ? "en" : "fr";

  return (
    <Button asChild variant="ghost" size="sm">
      <Link href={{ pathname, query }} locale={other} hrefLang={other}>
        {other.toUpperCase()}
        <span className="sr-only"> — {t(other)}</span>
      </Link>
    </Button>
  );
}

function SignOutButton({ onClick, disabled }: { onClick: () => void; disabled: boolean }) {
  const t = useTranslations("admin.auth");

  return (
    <Button variant="ghost" size="sm" onClick={onClick} disabled={disabled}>
      <LogOut aria-hidden />
      {t("signOut")}
    </Button>
  );
}
