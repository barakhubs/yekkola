"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { coursesEnroll } from "@yekkola/api-client/endpoints/enrollment";
import { useMeCoursesShow } from "@yekkola/api-client/endpoints/learning";
import { Button } from "@yekkola/ui/components/button";
import { useTranslations } from "next-intl";

import { useSession } from "@/features/auth/hooks/use-session";
import { Link, useRouter } from "@/i18n/navigation";

interface EnrollButtonProps {
  courseId: string;
  slug: string;
  isFree: boolean;
}

/**
 * The course page's main action (PRD-05 FR-01): sign in, enrol for free in one tap, or continue an enrolled
 * course. The API decides who may enrol; this only shows the right button.
 */
export function EnrollButton({ courseId, slug, isFree }: EnrollButtonProps) {
  const t = useTranslations("web");
  const errors = useTranslations("errors");
  const router = useRouter();
  const queryClient = useQueryClient();
  const { isSignedIn, isLoading, isSuspended } = useSession();
  // The learning view answers 403 when there's no active enrollment.
  const enrolled = useMeCoursesShow(courseId, {
    query: { enabled: isSignedIn && !isSuspended, retry: false, select: (r) => Boolean(success(r).data) },
  });
  // Only a 403 means "not enrolled"; offline or a server error says nothing either way.
  const enrollmentUnknown =
    enrolled.isError && !(enrolled.error instanceof ApiError && enrolled.error.status === 403);
  const enroll = useMutation({
    mutationFn: async () => success(await coursesEnroll(courseId)),
    onSuccess: async () => {
      await queryClient.invalidateQueries();
      router.push(`/learn/${courseId}`);
    },
  });

  if (isSuspended) {
    return null; // the account can't enrol (FR-15); the explanation is on its pages
  }

  if (isLoading || (isSignedIn && (enrolled.isPending || enrollmentUnknown))) {
    return (
      <Button size="lg" disabled aria-busy>
        {isFree ? t("course.enrollFree") : t("course.buy")}
      </Button>
    );
  }

  if (!isSignedIn) {
    return (
      <Button asChild size="lg" variant={isFree ? "accent" : "default"}>
        <Link href={`/login?next=${encodeURIComponent(`/courses/${slug}`)}`}>
          {isFree ? t("course.enrollFree") : t("course.buy")}
        </Link>
      </Button>
    );
  }

  if (enrolled.data) {
    return (
      <Button asChild size="lg">
        <Link href={`/learn/${courseId}`}>{t("enroll.continueLearning")}</Link>
      </Button>
    );
  }

  if (!isFree) {
    return (
      <Button asChild size="lg">
        <Link href={`/checkout?course=${courseId}`}>{t("course.buy")}</Link>
      </Button>
    );
  }

  return (
    <div className="grid gap-2">
      <Button
        size="lg"
        variant="accent"
        onClick={() => enroll.mutate()}
        disabled={enroll.isPending}
        aria-busy={enroll.isPending}
      >
        {enroll.isPending ? t("enroll.enrolling") : t("course.enrollFree")}
      </Button>
      {enroll.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {enroll.error instanceof ApiError
            ? (enroll.error.body.message ?? errors("generic"))
            : errors("network")}
        </p>
      ) : null}
    </div>
  );
}
