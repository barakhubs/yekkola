"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  adminBundlesStore,
  adminBundlesUpdate,
  getAdminBundlesIndexQueryKey,
} from "@yekkola/api-client/endpoints/bundle";
import type { AdminBundlesIndex200DataItem, BundleStatus } from "@yekkola/api-client/model";
import { formatMoney, moneyInputValue, parseMoneyInput, type Locale } from "@yekkola/i18n";
import { Button } from "@yekkola/ui/components/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@yekkola/ui/components/dialog";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Textarea } from "@yekkola/ui/components/textarea";
import { ArrowDown, ArrowUp, X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useState } from "react";

import { selectClass } from "@/features/common/styles";

import { CoursePicker } from "./pickers";
import { BUNDLE_STATES, useFormErrorText } from "./shared";

// `price` is null for a course that became free since: kept and flagged, never dropped silently.
type Picked = { id: string; title: string; price: { amount_minor: number; currency: string } | null };

/**
 * Create a platform bundle or edit any bundle (PRD-09 FR-13, PRD-05 FR-13): two to twenty published paid
 * courses in one currency, priced below their total. A professor's bundle keeps only their courses. The API
 * checks all of it again (prices, currency, collectable amount) and applies changes to new orders only.
 */
export function BundleDialog({
  bundle,
  trigger,
}: {
  bundle?: AdminBundlesIndex200DataItem;
  trigger: ReactNode;
}) {
  const f = useTranslations("admin.bundles.form");
  const t = useTranslations("admin.bundles");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useFormErrorText();
  const queryClient = useQueryClient();
  const owner = bundle?.professor ?? null;

  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [courses, setCourses] = useState<Picked[]>([]);
  const [price, setPrice] = useState("");
  const [status, setStatus] = useState<BundleStatus>("draft");

  const reset = () => {
    setTitle(bundle?.title ?? "");
    setDescription(bundle?.description ?? "");
    setCourses(
      (bundle?.courses ?? []).map((c) => ({
        id: c.id,
        title: c.title ?? c.slug,
        price: c.is_free ? null : c.price,
      })),
    );
    setPrice(bundle ? moneyInputValue(bundle.price, locale) : "");
    setStatus((bundle?.status as BundleStatus | undefined) ?? "draft");
  };

  const currency = courses.find((c) => c.price)?.price?.currency ?? bundle?.price.currency ?? null;
  const listTotal = courses.reduce((sum, c) => sum + (c.price?.amount_minor ?? 0), 0);
  const allPriced = courses.every((c) => c.price !== null && c.price.currency === currency);
  const priceMinor = currency ? parseMoneyInput(price, currency) : null;
  const priceOk = priceMinor !== null && priceMinor > 0 && priceMinor < listTotal;
  const valid =
    title.trim().length >= 3 && courses.length >= 2 && courses.length <= 20 && allPriced && priceOk;

  const save = useMutation({
    mutationFn: async () => {
      const body = {
        title: title.trim(),
        description: description.trim() || null,
        price_minor: priceMinor ?? 0,
        currency: currency ?? "",
        status,
        course_ids: courses.map((c) => c.id),
      };
      if (bundle) await adminBundlesUpdate(bundle.id, body);
      else await adminBundlesStore(body);
    },
    onSuccess: async () => {
      setOpen(false);
      await queryClient.invalidateQueries({ queryKey: getAdminBundlesIndexQueryKey() });
    },
  });

  const move = (index: number, by: -1 | 1) =>
    setCourses((list) => {
      const next = [...list];
      const [moved] = next.splice(index, 1);
      if (moved) next.splice(index + by, 0, moved);
      return next;
    });

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          reset();
          save.reset();
        }
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent closeLabel={common("close")} className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{bundle ? f("editTitle", { title: bundle.title }) : f("createTitle")}</DialogTitle>
          <DialogDescription>{bundle ? f("editHint") : f("createHint")}</DialogDescription>
        </DialogHeader>
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (valid && !save.isPending) save.mutate();
          }}
        >
          {owner ? (
            <p className="text-sm text-muted-foreground">{f("ownedBy", { name: owner.display_name })}</p>
          ) : null}
          <div className="grid gap-2">
            <Label htmlFor="bundle-title">{f("titleLabel")}</Label>
            <Input
              id="bundle-title"
              maxLength={120}
              value={title}
              onChange={(e) => setTitle(e.target.value)}
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="bundle-description">{f("description")}</Label>
            <Textarea
              id="bundle-description"
              rows={3}
              maxLength={2000}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </div>

          <section aria-labelledby="bundle-courses" className="grid gap-2">
            <h3 id="bundle-courses" className="text-sm font-semibold">
              {f("courses")}
            </h3>
            {courses.length === 0 ? <p className="text-sm text-muted-foreground">{f("noCourses")}</p> : null}
            <ol className="grid gap-1 text-sm">
              {courses.map((c, i) => (
                <li
                  key={c.id}
                  className="flex items-center justify-between gap-2 rounded-md border px-3 py-1.5"
                >
                  <span className="grid">
                    <span>{c.title}</span>
                    {c.price && c.price.currency === currency ? (
                      <span className="text-xs text-muted-foreground">{formatMoney(c.price, locale)}</span>
                    ) : (
                      <span className="text-xs text-destructive">
                        {c.price ? f("otherCurrency") : f("notSellable")}
                      </span>
                    )}
                  </span>
                  <span className="flex gap-1">
                    <Button
                      type="button"
                      size="icon"
                      variant="ghost"
                      aria-label={t("moveUp", { title: c.title })}
                      disabled={i === 0}
                      onClick={() => move(i, -1)}
                    >
                      <ArrowUp aria-hidden />
                    </Button>
                    <Button
                      type="button"
                      size="icon"
                      variant="ghost"
                      aria-label={t("moveDown", { title: c.title })}
                      disabled={i === courses.length - 1}
                      onClick={() => move(i, 1)}
                    >
                      <ArrowDown aria-hidden />
                    </Button>
                    <Button
                      type="button"
                      size="icon"
                      variant="ghost"
                      aria-label={f("remove", { title: c.title })}
                      onClick={() => setCourses((list) => list.filter((x) => x.id !== c.id))}
                    >
                      <X aria-hidden />
                    </Button>
                  </span>
                </li>
              ))}
            </ol>
            {courses.length < 20 ? (
              <CoursePicker
                label={f("courseSearch")}
                empty={f("noResults")}
                professorId={owner?.id}
                isDisabled={(c) =>
                  courses.some((x) => x.id === c.id)
                    ? f("added")
                    : currency !== null && c.price?.currency !== currency
                      ? f("otherCurrency")
                      : false
                }
                onPick={(c) => {
                  const coursePrice = c.price;
                  if (!coursePrice || courses.some((x) => x.id === c.id)) return;
                  setCourses((list) => [...list, { id: c.id, title: c.title ?? c.slug, price: coursePrice }]);
                }}
              />
            ) : null}
            {currency && courses.length > 0 ? (
              <p className="text-xs text-muted-foreground">
                {f("listTotal", { total: formatMoney({ amount_minor: listTotal, currency }, locale) })}
              </p>
            ) : null}
          </section>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="bundle-price">{f("price", { currency: currency ?? "—" })}</Label>
              <Input
                id="bundle-price"
                inputMode="decimal"
                disabled={currency === null}
                value={price}
                onChange={(e) => setPrice(e.target.value)}
              />
              {currency === null ? (
                <p className="text-xs text-muted-foreground">{f("pickCoursesFirst")}</p>
              ) : price && !priceOk ? (
                <p className="text-sm text-destructive">{f("badPrice")}</p>
              ) : null}
            </div>
            <div className="grid gap-2">
              <Label htmlFor="bundle-status">{f("status")}</Label>
              <select
                id="bundle-status"
                className={selectClass}
                value={status}
                onChange={(e) => setStatus(e.target.value as BundleStatus)}
              >
                {BUNDLE_STATES.map((value) => (
                  <option key={value} value={value}>
                    {t(`status.${value}`)}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {save.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(save.error)}
            </p>
          ) : null}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              {common("cancel")}
            </Button>
            <Button type="submit" disabled={!valid || save.isPending} aria-busy={save.isPending}>
              {bundle ? f("save") : f("create")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
