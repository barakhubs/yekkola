"use client";

import { success } from "@yekkola/api-client";
import { useAdminCoursesIndex } from "@yekkola/api-client/endpoints/course";
import { useAdminProfessorsIndex } from "@yekkola/api-client/endpoints/professor";
import type { AdminCourseResource, AdminProfessorResource } from "@yekkola/api-client/model";
import { formatMoney, type Locale } from "@yekkola/i18n";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { type KeyboardEvent, type ReactNode, useId, useState } from "react";

import { useDebounced } from "./shared";

// The pickers sit inside dialog forms: Enter in the search box must not submit the form.
const keepEnter = (e: KeyboardEvent<HTMLInputElement>) => {
  if (e.key === "Enter") e.preventDefault();
};

function Results<T>({
  items,
  loading,
  error,
  empty,
  render,
  onPick,
  isDisabled,
}: {
  items: T[];
  loading: boolean;
  error: boolean;
  empty: string;
  render: (item: T) => ReactNode;
  onPick: (item: T) => void;
  isDisabled?: (item: T) => ReactNode | false;
}) {
  const errors = useTranslations("errors");
  if (error) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {errors("generic")}
      </p>
    );
  }
  if (!loading && items.length === 0) {
    return <p className="text-sm text-muted-foreground">{empty}</p>;
  }

  return (
    <ul className="grid max-h-56 gap-1 overflow-y-auto" aria-busy={loading}>
      {items.map((item, i) => {
        const disabled = isDisabled?.(item) ?? false;
        return (
          <li key={i}>
            <button
              type="button"
              disabled={disabled !== false}
              onClick={() => onPick(item)}
              className="flex w-full items-center justify-between gap-2 rounded-md border px-3 py-1.5 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
            >
              {render(item)}
              {disabled !== false ? <span className="text-xs text-muted-foreground">{disabled}</span> : null}
            </button>
          </li>
        );
      })}
    </ul>
  );
}

/**
 * Search the published paid courses (admin courses list, `catalog.view`). Free and taken-down courses aren't
 * offered: neither a coupon nor a bundle means anything for them.
 */
export function CoursePicker({
  label,
  empty,
  onPick,
  professorId,
  isDisabled,
}: {
  label: string;
  empty: string;
  onPick: (course: AdminCourseResource) => void;
  /** Only this professor's courses (a professor's own bundle). */
  professorId?: string | null;
  isDisabled?: (course: AdminCourseResource) => ReactNode | false;
}) {
  const id = useId();
  const locale = useLocale() as Locale;
  const [text, setText] = useState("");
  const q = useDebounced(text.trim());
  const courses = useAdminCoursesIndex(
    {
      per_page: 10,
      "filter[status]": "published",
      ...(q ? { "filter[q]": q } : {}),
      ...(professorId ? { "filter[professor_profile_id]": professorId } : {}),
    },
    {
      query: {
        select: (r) =>
          success(r).data.filter((c) => !c.is_free && c.price !== null && c.taken_down_at === null),
      },
    },
  );

  return (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type="search"
        value={text}
        onChange={(e) => setText(e.target.value)}
        onKeyDown={keepEnter}
      />
      <Results
        items={courses.data ?? []}
        loading={courses.isFetching}
        error={courses.isError}
        empty={empty}
        onPick={onPick}
        isDisabled={isDisabled}
        render={(c) => (
          <span className="grid">
            <span className="font-semibold">{c.title ?? c.slug}</span>
            <span className="text-xs text-muted-foreground">
              {c.professor.display_name}
              {c.price ? ` · ${formatMoney(c.price, locale)}` : ""}
            </span>
          </span>
        )}
      />
    </div>
  );
}

/** Search active professors (admin professors list, `professors.manage`). */
export function ProfessorPicker({
  label,
  empty,
  onPick,
}: {
  label: string;
  empty: string;
  onPick: (professor: AdminProfessorResource) => void;
}) {
  const id = useId();
  const [text, setText] = useState("");
  const q = useDebounced(text.trim());
  const professors = useAdminProfessorsIndex(
    { per_page: 10, "filter[status]": "active", ...(q ? { "filter[search]": q } : {}) },
    { query: { select: (r) => success(r).data } },
  );

  return (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type="search"
        value={text}
        onChange={(e) => setText(e.target.value)}
        onKeyDown={keepEnter}
      />
      <Results
        items={professors.data ?? []}
        loading={professors.isFetching}
        error={professors.isError}
        empty={empty}
        onPick={onPick}
        render={(p) => <span className="font-semibold">{p.display_name}</span>}
      />
    </div>
  );
}
