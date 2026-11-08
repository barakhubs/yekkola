"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  adminThreadRepliesHide,
  adminThreadRepliesRestore,
  adminThreadsHide,
  adminThreadsRestore,
  getAdminThreadsIndexQueryKey,
  useAdminThreadsIndex,
} from "@yekkola/api-client/endpoints/qa-moderation";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Badge } from "@yekkola/ui/components/badge";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { useLocale, useTranslations } from "next-intl";
import { parseAsInteger, parseAsString, useQueryState } from "nuqs";
import { type FormEvent, useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";
import { useTableLabels } from "@/features/common/table";
import { Link } from "@/i18n/navigation";

type Target = { kind: "thread" | "reply"; id: string; hide: boolean };

/**
 * Lesson Q&A moderation (PRD-09 FR-10): every question with its replies, hidden ones included, newest first. Hide
 * or restore a question or a single reply, each with a reason in the audit log; hidden posts stay visible to their
 * author only.
 */
export function QaModeration() {
  const t = useTranslations("admin.qaModeration");
  const common = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const labels = useTableLabels();
  const queryClient = useQueryClient();
  const [hidden, setHidden] = useQueryState("hidden", parseAsString.withDefault("all"));
  const [search, setSearch] = useQueryState("q", parseAsString.withDefault(""));
  const [page, setPage] = useQueryState("page", parseAsInteger.withDefault(1));
  const [draft, setDraft] = useState(search);

  const threads = useAdminThreadsIndex(
    {
      page,
      per_page: 20,
      ...(hidden === "all" ? {} : { "filter[hidden]": hidden }),
      ...(search ? { "filter[q]": search } : {}),
    },
    { query: { select: (r) => success(r), placeholderData: (previous) => previous } },
  );
  const act = useMutation({
    mutationFn: ({ kind, id, hide, note }: Target & { note: string }) =>
      kind === "thread"
        ? hide
          ? adminThreadsHide(id, { note })
          : adminThreadsRestore(id, { note })
        : hide
          ? adminThreadRepliesHide(id, { note })
          : adminThreadRepliesRestore(id, { note }),
    onSettled: () => queryClient.invalidateQueries({ queryKey: getAdminThreadsIndexQueryKey() }),
  });
  const lastPage = threads.data?.meta.last_page ?? 1;

  const action = (target: Target) => (
    <ConfirmDialog
      trigger={
        <Button size="sm" variant={target.hide ? "destructive" : "outline"}>
          {target.hide ? t("hide") : t("restore")}
        </Button>
      }
      title={t(`confirm.${target.kind}.${target.hide ? "hide" : "restore"}`)}
      description={target.hide ? t("hideHint") : t("restoreHint")}
      reason={{ label: t("reason"), minLength: 3 }}
      confirmLabel={target.hide ? t("hide") : t("restore")}
      cancelLabel={common("cancel")}
      destructive={target.hide}
      onConfirm={async (note) => {
        await act.mutateAsync({ ...target, note });
      }}
      error={act.isError && act.variables?.id === target.id ? errorText(act.error) : undefined}
      onOpenChange={(open) => {
        if (open) act.reset();
      }}
    />
  );

  return (
    <div className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader title={t("title")} description={t("intro")} />

      <div className="grid gap-4 sm:grid-cols-2">
        <form
          role="search"
          className="grid gap-2"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            void setSearch(draft.trim() || null);
            void setPage(1);
          }}
        >
          <Label htmlFor="qa-search">{t("search")}</Label>
          <Input id="qa-search" type="search" value={draft} onChange={(e) => setDraft(e.target.value)} />
        </form>
        <div className="grid gap-2">
          <Label htmlFor="qa-hidden">{t("filter")}</Label>
          <select
            id="qa-hidden"
            className={selectClass}
            value={hidden}
            onChange={(e) => {
              void setHidden(e.target.value);
              void setPage(1);
            }}
          >
            <option value="all">{t("all")}</option>
            <option value="true">{t("withHidden")}</option>
            <option value="false">{t("withoutHidden")}</option>
          </select>
        </div>
      </div>

      {threads.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(threads.error)}
        </p>
      ) : null}
      {threads.data?.data.length === 0 ? <p className="text-sm text-muted-foreground">{t("empty")}</p> : null}

      <ul className="grid gap-3" aria-busy={threads.isFetching}>
        {(threads.data?.data ?? []).map((thread) => (
          <li key={thread.id} className="grid gap-3 rounded-xl border bg-background p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="flex flex-wrap items-center gap-2 font-semibold">
                {thread.title}
                {thread.status === "answered" ? <Badge variant="secondary">{t("answered")}</Badge> : null}
                {thread.hidden_at ? <Badge variant="destructive">{t("hidden")}</Badge> : null}
              </span>
              <span className="text-xs text-muted-foreground">
                {formatDateTime(thread.created_at, locale)}
              </span>
            </div>
            <span className="text-xs text-muted-foreground">
              <Link href={`/users/${thread.author.id}`} className="underline underline-offset-2">
                {thread.author.name ?? "—"}
              </Link>{" "}
              · {thread.course.title ?? thread.course.slug}
            </span>
            <p className="text-sm whitespace-pre-line">{thread.body}</p>
            {thread.hidden_reason ? (
              <p className="text-xs text-muted-foreground">
                {t("hiddenBecause", { reason: thread.hidden_reason })}
              </p>
            ) : null}
            <div className="flex justify-end">
              {action({ kind: "thread", id: thread.id, hide: thread.can.hide })}
            </div>

            {thread.replies.length > 0 ? (
              <ul className="grid gap-2 border-l-2 pl-4">
                {thread.replies.map((reply) => (
                  <li key={reply.id} className="grid gap-1 rounded-md bg-muted/50 p-3 text-sm">
                    <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                      <Link
                        href={`/users/${reply.author.id}`}
                        className="font-semibold underline underline-offset-2"
                      >
                        {reply.author.name ?? "—"}
                      </Link>
                      {reply.is_professor ? <Badge>{t("professor")}</Badge> : null}
                      {reply.hidden_at ? <Badge variant="destructive">{t("hidden")}</Badge> : null}
                      {formatDateTime(reply.created_at, locale)}
                    </span>
                    <p className="whitespace-pre-line">{reply.body}</p>
                    {reply.hidden_reason ? (
                      <p className="text-xs text-muted-foreground">
                        {t("hiddenBecause", { reason: reply.hidden_reason })}
                      </p>
                    ) : null}
                    <div className="flex justify-end">
                      {action({ kind: "reply", id: reply.id, hide: reply.can.hide })}
                    </div>
                  </li>
                ))}
              </ul>
            ) : null}
          </li>
        ))}
      </ul>

      {lastPage > 1 ? (
        <nav aria-label={labels.pagination} className="flex items-center justify-between gap-4">
          <Button variant="outline" disabled={page <= 1} onClick={() => void setPage(page - 1)}>
            {labels.previous}
          </Button>
          <span className="text-sm text-muted-foreground">{labels.page(page, lastPage)}</span>
          <Button variant="outline" disabled={page >= lastPage} onClick={() => void setPage(page + 1)}>
            {labels.next}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
