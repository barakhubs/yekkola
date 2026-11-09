"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  getMeWishlistIndexQueryKey,
  meWishlistDestroy,
  meWishlistStore,
  useMeWishlistIndex,
} from "@yekkola/api-client/endpoints/wishlist";
import { formatMoney, type Locale } from "@yekkola/i18n";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Heart } from "lucide-react";
import Image from "next/image";
import { useLocale, useTranslations } from "next-intl";

import { useSession } from "@/features/auth/hooks/use-session";
import { Link } from "@/i18n/navigation";

/** One list per account (enough for a heart button and the page; the API keeps listed courses only). */
const WISHLIST_PARAMS = { per_page: 100 } as const;

function useWishlist(enabled = true) {
  return useMeWishlistIndex(WISHLIST_PARAMS, { query: { enabled, select: (r) => success(r).data } });
}

function useToggleWishlist() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ courseId, saved }: { courseId: string; saved: boolean }) => {
      if (saved) await meWishlistDestroy(courseId);
      else await meWishlistStore({ course_id: courseId });
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: getMeWishlistIndexQueryKey() }),
  });
}

/** "Save for later" on the course page (PRD-04 FR-08). Signed-in visitors only; a link to sign in otherwise. */
export function WishlistButton({ courseId, slug }: { courseId: string; slug: string }) {
  const t = useTranslations("web.wishlist");
  const { isSignedIn, isLoading } = useSession();
  const wishlist = useWishlist(isSignedIn);
  const toggle = useToggleWishlist();

  if (isLoading) return null;
  if (!isSignedIn) {
    return (
      <Button asChild variant="ghost" size="sm" className="w-fit">
        <Link href={`/login?next=${encodeURIComponent(`/courses/${slug}`)}`}>
          <Heart aria-hidden />
          {t("save")}
        </Link>
      </Button>
    );
  }

  const saved = wishlist.data?.some((c) => c.id === courseId) ?? false;

  return (
    <Button
      variant="ghost"
      size="sm"
      className="w-fit"
      aria-pressed={saved}
      disabled={wishlist.isPending || toggle.isPending}
      onClick={() => toggle.mutate({ courseId, saved })}
    >
      <Heart aria-hidden className={saved ? "fill-current text-primary" : undefined} />
      {saved ? t("saved") : t("save")}
    </Button>
  );
}

/** My wishlist (PRD-04 FR-08): saved courses, newest first, with a way to open or remove each. */
export function WishlistPage() {
  const t = useTranslations("web.wishlist");
  const errors = useTranslations("errors");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const wishlist = useWishlist();
  const toggle = useToggleWishlist();

  return (
    <div className="mx-auto grid max-w-4xl gap-6 px-4 py-8 sm:py-12">
      <PageHeader title={t("title")} description={t("intro")} />
      {wishlist.isError ? (
        <EmptyState title={errors("generic")} />
      ) : wishlist.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : wishlist.data.length === 0 ? (
        <EmptyState
          title={t("empty")}
          action={
            <Button asChild>
              <Link href="/courses">{t("browse")}</Link>
            </Button>
          }
        />
      ) : (
        <ul className="grid gap-3">
          {wishlist.data.map((c) => (
            <li key={c.id} className="flex items-center gap-3 rounded-xl border p-3">
              {c.cover_url ? (
                <Image
                  src={c.cover_url}
                  alt=""
                  width={128}
                  height={72}
                  className="hidden aspect-video w-32 rounded-md object-cover sm:block"
                />
              ) : null}
              <div className="grid min-w-0 flex-1 gap-0.5">
                <Link href={`/courses/${c.slug}`} className="font-semibold text-primary hover:underline">
                  {c.title ?? c.slug}
                </Link>
                <span className="text-sm text-muted-foreground">
                  {c.professor.display_name} ·{" "}
                  {c.is_free ? common("free") : c.price ? formatMoney(c.price, locale) : "—"}
                </span>
              </div>
              <Button
                variant="ghost"
                size="sm"
                disabled={toggle.isPending}
                onClick={() => toggle.mutate({ courseId: c.id, saved: true })}
                aria-label={t("removeNamed", { title: c.title ?? c.slug })}
              >
                {t("remove")}
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
