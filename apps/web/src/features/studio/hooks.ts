"use client";

import { useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { useProfessorAuthoringOptions } from "@yekkola/api-client/endpoints/authoring-options";
import {
  getProfessorCoursesIndexQueryKey,
  getProfessorCoursesShowQueryKey,
  useProfessorCoursesShow,
} from "@yekkola/api-client/endpoints/course";
import { getProfessorDraftShowQueryKey, useProfessorDraftShow } from "@yekkola/api-client/endpoints/draft";
import { useTranslations } from "next-intl";
import { useCallback } from "react";

const noRetryOn4xx = (failures: number, error: unknown) =>
  !(error instanceof ApiError && error.status < 500) && failures < 2;

/** The course's settings and lifecycle (status, price, live and draft versions). */
export function useStudioCourse(courseId: string) {
  return useProfessorCoursesShow(courseId, {
    query: { select: (r) => success(r).data, retry: noRetryOn4xx },
  });
}

/**
 * The draft being edited, with its curriculum and the submit checklist. A published course without a draft
 * answers `course.no_draft` (404): the editor then offers to start one.
 */
export function useStudioDraft(courseId: string, enabled = true) {
  return useProfessorDraftShow(courseId, {
    query: { select: (r) => success(r).data, retry: noRetryOn4xx, enabled },
  });
}

/** Choices and limits from platform settings (currencies, price limits, upload sizes, levels…). */
export function useAuthoringOptions() {
  return useProfessorAuthoringOptions({
    query: { select: (r) => success(r).data, staleTime: 10 * 60_000 },
  });
}

/** After any write: the course, its draft (and checklist) and the course list are read again. */
export function useRefreshCourse(courseId: string) {
  const queryClient = useQueryClient();

  return useCallback(
    () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: getProfessorCoursesShowQueryKey(courseId) }),
        queryClient.invalidateQueries({ queryKey: getProfessorDraftShowQueryKey(courseId) }),
        queryClient.invalidateQueries({ queryKey: getProfessorCoursesIndexQueryKey() }),
      ]).then(() => undefined),
    [courseId, queryClient],
  );
}

/** The API's own (translated) message for an error, or a generic one. */
export function useErrorText() {
  const errors = useTranslations("errors");

  return useCallback(
    (error: unknown) => (error instanceof ApiError ? (error.title ?? errors("generic")) : errors("network")),
    [errors],
  );
}

/** First validation message per field (`title`, `questions.0.prompt`…), empty when the error has none. */
export function fieldErrorsOf(error: unknown): Record<string, string> {
  return error instanceof ApiError ? error.fieldErrors() : {};
}
