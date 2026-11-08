"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  getLessonsThreadsIndexQueryKey,
  getProfessorThreadsIndexQueryKey,
  getThreadsShowQueryKey,
  lessonsThreadsStore,
  threadsRepliesStore,
  useLessonsThreadsIndex,
  useThreadsShow,
} from "@yekkola/api-client/endpoints/thread";
import type { ThreadResource } from "@yekkola/api-client/model";
import { formatDateTime, type Locale } from "@yekkola/i18n";
import { Badge } from "@yekkola/ui/components/badge";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Textarea } from "@yekkola/ui/components/textarea";
import { cn } from "@yekkola/ui/lib/utils";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, useId, useState } from "react";

import { ReportButton } from "@/features/moderation/report-button";
import { useErrorText } from "@/features/studio/hooks";

/**
 * Lesson Q&A (PRD-06 FR-13): questions on this lesson, latest activity first; ask one; open one to read the
 * replies (the professor's highlighted) and reply. Who may take part is the API's decision.
 */
export function LessonQa({ lessonUid, returnTo }: { lessonUid: string; returnTo: string }) {
  const t = useTranslations("web.qa");
  const errorText = useErrorText();
  const [page, setPage] = useState(1);
  const [openId, setOpenId] = useState<string | null>(null);
  const threads = useLessonsThreadsIndex(
    lessonUid,
    { page, per_page: 10 },
    {
      query: { select: (r) => success(r), placeholderData: (previous) => previous },
    },
  );
  const lastPage = threads.data?.meta.last_page ?? 1;

  return (
    <section aria-labelledby="qa-heading" className="grid gap-4 border-t pt-6">
      <h2 id="qa-heading" className="text-lg font-bold">
        {t("title")}
      </h2>
      <AskForm lessonUid={lessonUid} onAsked={(id) => setOpenId(id)} />

      {threads.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(threads.error)}
        </p>
      ) : null}
      {threads.data?.data.length === 0 ? <p className="text-sm text-muted-foreground">{t("empty")}</p> : null}

      <ul className="grid gap-3" aria-busy={threads.isFetching}>
        {(threads.data?.data ?? []).map((thread) => (
          <li key={thread.id} className="rounded-lg border">
            <button
              type="button"
              className="grid w-full gap-1 p-3 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              aria-expanded={openId === thread.id}
              onClick={() => setOpenId(openId === thread.id ? null : thread.id)}
            >
              <ThreadHeader thread={thread} />
            </button>
            {openId === thread.id ? <ThreadDetail threadId={thread.id} returnTo={returnTo} /> : null}
          </li>
        ))}
      </ul>

      {lastPage > 1 ? (
        <nav aria-label={t("pages")} className="flex items-center justify-between gap-3">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t("previous")}
          </Button>
          <span className="text-sm text-muted-foreground">{t("page", { page, last: lastPage })}</span>
          <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>
            {t("next")}
          </Button>
        </nav>
      ) : null}
    </section>
  );
}

export function ThreadHeader({ thread }: { thread: ThreadResource }) {
  const t = useTranslations("web.qa");
  const locale = useLocale() as Locale;

  return (
    <>
      <span className="flex flex-wrap items-center gap-2">
        <span className="font-semibold">{thread.title}</span>
        {thread.status === "answered" ? <Badge variant="secondary">{t("answered")}</Badge> : null}
        {thread.hidden ? <Badge variant="destructive">{t("hidden")}</Badge> : null}
      </span>
      <span className="text-xs text-muted-foreground">
        {t("askedBy", { name: thread.author.name ?? t("anonymous") })} ·{" "}
        {formatDateTime(thread.created_at, locale)} · {t("replies", { count: thread.reply_count })}
      </span>
    </>
  );
}

export function ThreadDetail({ threadId, returnTo }: { threadId: string; returnTo: string }) {
  const t = useTranslations("web.qa");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const thread = useThreadsShow(threadId, { query: { select: (r) => success(r).data } });

  if (thread.isPending) {
    return <p className="px-3 pb-3 text-sm text-muted-foreground">{t("loading")}</p>;
  }
  if (thread.isError) {
    return (
      <p role="alert" className="px-3 pb-3 text-sm text-destructive">
        {errorText(thread.error)}
      </p>
    );
  }

  const data = thread.data;

  return (
    <div className="grid gap-3 border-t px-3 py-3">
      <p className="text-sm whitespace-pre-line">{data.body}</p>
      {!data.author.is_me && !data.hidden ? (
        <div className="flex justify-end">
          <ReportButton type="thread" id={data.id} returnTo={returnTo} />
        </div>
      ) : null}
      <ul className="grid gap-2">
        {(data.replies ?? []).map((reply) => (
          <li
            key={reply.id}
            className={cn(
              "grid gap-1 rounded-md p-3 text-sm",
              reply.author.is_professor ? "border-l-4 border-primary bg-accent" : "bg-muted/50",
            )}
          >
            <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
              <span className="font-semibold text-foreground">{reply.author.name ?? t("anonymous")}</span>
              {reply.author.is_professor ? <Badge>{t("professor")}</Badge> : null}
              {reply.hidden ? <Badge variant="destructive">{t("hidden")}</Badge> : null}
              {formatDateTime(reply.created_at, locale)}
            </span>
            <p className="whitespace-pre-line">{reply.body}</p>
            {!reply.author.is_me && !reply.hidden ? (
              <div className="flex justify-end">
                <ReportButton type="reply" id={reply.id} returnTo={returnTo} />
              </div>
            ) : null}
          </li>
        ))}
      </ul>
      {data.hidden ? null : <ReplyForm thread={data} />}
    </div>
  );
}

function AskForm({ lessonUid, onAsked }: { lessonUid: string; onAsked: (id: string) => void }) {
  const t = useTranslations("web.qa");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const id = useId();
  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const ask = useMutation({
    mutationFn: async () =>
      success(await lessonsThreadsStore(lessonUid, { title: title.trim(), body: body.trim() })).data,
    onSuccess: async (thread) => {
      setTitle("");
      setBody("");
      setOpen(false);
      await queryClient.invalidateQueries({ queryKey: getLessonsThreadsIndexQueryKey(lessonUid) });
      onAsked(thread.id);
    },
  });
  const fieldErrors = ask.error instanceof ApiError ? ask.error.fieldErrors() : {};

  if (!open) {
    return (
      <div>
        <Button variant="outline" onClick={() => setOpen(true)}>
          {t("ask")}
        </Button>
      </div>
    );
  }

  return (
    <form
      className="grid gap-3 rounded-lg border p-3"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (!ask.isPending) ask.mutate();
      }}
    >
      <div className="grid gap-2">
        <Label htmlFor={`${id}-title`}>{t("questionTitle")}</Label>
        <Input
          id={`${id}-title`}
          value={title}
          maxLength={200}
          required
          onChange={(e) => setTitle(e.target.value)}
          aria-invalid={fieldErrors.title ? true : undefined}
        />
        {fieldErrors.title ? <p className="text-sm text-destructive">{fieldErrors.title}</p> : null}
      </div>
      <div className="grid gap-2">
        <Label htmlFor={`${id}-body`}>{t("questionBody")}</Label>
        <Textarea
          id={`${id}-body`}
          value={body}
          maxLength={5000}
          required
          onChange={(e) => setBody(e.target.value)}
          aria-invalid={fieldErrors.body ? true : undefined}
        />
        {fieldErrors.body ? <p className="text-sm text-destructive">{fieldErrors.body}</p> : null}
      </div>
      {ask.isError && Object.keys(fieldErrors).length === 0 ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(ask.error)}
        </p>
      ) : null}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
          {t("cancel")}
        </Button>
        <Button type="submit" disabled={ask.isPending} aria-busy={ask.isPending}>
          {t("post")}
        </Button>
      </div>
    </form>
  );
}

function ReplyForm({ thread }: { thread: ThreadResource }) {
  const t = useTranslations("web.qa");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const id = useId();
  const [body, setBody] = useState("");
  const reply = useMutation({
    mutationFn: async () => success(await threadsRepliesStore(thread.id, { body: body.trim() })).data,
    onSuccess: async (updated) => {
      setBody("");
      queryClient.setQueryData(getThreadsShowQueryKey(thread.id), {
        data: { data: updated },
        status: 200,
        headers: new Headers(),
      });
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: getLessonsThreadsIndexQueryKey(thread.lesson_uid) }),
        queryClient.invalidateQueries({ queryKey: getProfessorThreadsIndexQueryKey() }),
      ]);
    },
  });

  return (
    <form
      className="grid gap-2"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (!reply.isPending && body.trim() !== "") reply.mutate();
      }}
    >
      <Label htmlFor={`${id}-reply`}>{t("yourReply")}</Label>
      <Textarea id={`${id}-reply`} value={body} maxLength={5000} onChange={(e) => setBody(e.target.value)} />
      {reply.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(reply.error)}
        </p>
      ) : null}
      <div className="flex justify-end">
        <Button
          type="submit"
          size="sm"
          disabled={reply.isPending || body.trim() === ""}
          aria-busy={reply.isPending}
        >
          {t("reply")}
        </Button>
      </div>
    </form>
  );
}
