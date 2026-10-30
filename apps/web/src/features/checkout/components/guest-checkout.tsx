"use client";

import { useMutation } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { guestOrdersStore } from "@yekkola/api-client/endpoints/guest-order";
import type { CourseDetailResource } from "@yekkola/api-client/model";
import { formatMoney, type Locale } from "@yekkola/i18n";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { ShieldCheck } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, useRef, useState, useSyncExternalStore } from "react";

import { Turnstile, type TurnstileHandle } from "@/features/auth/components/turnstile";
import { useSession } from "@/features/auth/hooks/use-session";
import { fieldErrorsOf, useErrorText } from "@/features/studio/hooks";
import { Link, useRouter } from "@/i18n/navigation";

import { withIdempotencyKey } from "../idempotency";
import { msisdnOf } from "./payer-input";
import { PaymentStatus } from "./payment-status";

/** Where a guest order's token is kept: in the page address (after `#`, never sent to a server). */
export function guestOrderHref(orderId: string, token: string): string {
  return `/gift/${orderId}#${encodeURIComponent(token)}`;
}

/**
 * A parent pays for a student without an account (PRD-05 FR-14): the student's number gets the course, the payer's
 * number gets the payment request. The order page then asks for the operator, like any checkout.
 */
export function GuestCheckout({ course }: { course: CourseDetailResource }) {
  const t = useTranslations("web.gift");
  const checkout = useTranslations("web.checkout");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const router = useRouter();
  const { isSignedIn } = useSession();
  const turnstile = useRef<TurnstileHandle>(null);
  const [botToken, setBotToken] = useState<string | null>(null);
  const [student, setStudent] = useState("");
  const [payer, setPayer] = useState("");

  const create = useMutation({
    mutationFn: async () => {
      const to = msisdnOf(student) ?? "";
      const from = msisdnOf(payer);
      return withIdempotencyKey(`guest:${course.id}:${to}:${from ?? ""}`, async (key) =>
        success(
          await guestOrdersStore(
            { course_ids: [course.id], beneficiary_phone: to, payer_msisdn: from, bot_token: botToken },
            { headers: { "Idempotency-Key": key } },
          ),
        ),
      );
    },
    onSuccess: (response) => router.push(guestOrderHref(response.data.id, response.access_token)),
    // Bot-check tokens are single use.
    onSettled: () => turnstile.current?.reset(),
  });
  const errors = fieldErrorsOf(create.error);

  if (isSignedIn) {
    return (
      <div className="mx-auto grid max-w-xl gap-4 px-4 py-10">
        <PageHeader title={t("title", { course: course.title ?? "" })} description={t("signedIn")} />
        <Button asChild className="w-fit">
          <Link href={`/checkout?course=${course.id}`}>{t("toCheckout")}</Link>
        </Button>
      </div>
    );
  }

  return (
    <form
      noValidate
      className="mx-auto grid max-w-xl gap-6 px-4 py-8 sm:py-12"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (botToken && student.trim() && !create.isPending) create.mutate();
      }}
    >
      <PageHeader title={t("title", { course: course.title ?? "" })} description={t("intro")} />

      <p className="flex justify-between gap-4 rounded-xl border p-4 font-bold">
        <span>{course.title}</span>
        <span className="tabular-nums">{course.price ? formatMoney(course.price, locale) : null}</span>
      </p>

      <div className="grid gap-2">
        <Label htmlFor="gift-student">{checkout("studentPhone")}</Label>
        <Input
          id="gift-student"
          type="tel"
          inputMode="tel"
          value={student}
          aria-describedby="gift-student-hint"
          onChange={(e) => setStudent(e.target.value)}
        />
        <p id="gift-student-hint" className="text-xs text-muted-foreground">
          {checkout("studentPhoneHint")}
        </p>
        {errors.beneficiary_phone ? (
          <p className="text-sm text-destructive">{errors.beneficiary_phone}</p>
        ) : null}
      </div>

      <div className="grid gap-2">
        <Label htmlFor="gift-payer">{t("payer")}</Label>
        <Input
          id="gift-payer"
          type="tel"
          inputMode="tel"
          autoComplete="tel"
          value={payer}
          aria-describedby="gift-payer-hint"
          onChange={(e) => setPayer(e.target.value)}
        />
        <p id="gift-payer-hint" className="text-xs text-muted-foreground">
          {t("payerHint")}
        </p>
        {errors.payer_msisdn ? <p className="text-sm text-destructive">{errors.payer_msisdn}</p> : null}
      </div>

      <Turnstile ref={turnstile} onToken={setBotToken} locale={locale} />

      {create.isError && Object.keys(errors).length === 0 ? (
        <p role="alert" className="text-sm text-destructive">
          {create.error instanceof ApiError && create.error.code === "order.nothing_to_buy"
            ? t("alreadyOwned")
            : errorText(create.error)}
        </p>
      ) : null}

      <Button
        type="submit"
        size="lg"
        disabled={!botToken || !student.trim() || create.isPending}
        aria-busy={create.isPending}
      >
        {t("continue")}
      </Button>
      <p className="flex items-start gap-2 text-xs text-muted-foreground">
        <ShieldCheck aria-hidden className="size-4 shrink-0" />
        {checkout("secure")}
      </p>
    </form>
  );
}

const noSubscribe = () => () => {};

/** The guest order page: reads the token from the address, then shows the usual payment steps. */
export function GuestOrderStatus({ orderId }: { orderId: string }) {
  const t = useTranslations("web.gift");
  // Unknown while rendering on the server (no address there); then the part after `#`.
  const token = useSyncExternalStore(
    noSubscribe,
    () => decodeURIComponent(window.location.hash.slice(1)),
    () => null,
  );

  if (token === null) return null;
  if (!token) {
    return <p className="mx-auto max-w-xl px-4 py-16 text-center">{t("missingToken", { order: orderId })}</p>;
  }

  return <PaymentStatus orderId={orderId} guestToken={token} />;
}
