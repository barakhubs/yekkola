"use client";

import { useMutation } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import { lessonsQuizAttemptsStore, quizAttemptsSubmit } from "@yekkola/api-client/endpoints/quiz-attempt";
import type {
  LearningViewResourceSectionsItemLessonsItemQuiz,
  QuizAttemptResource,
} from "@yekkola/api-client/model";
import { Button } from "@yekkola/ui/components/button";
import { cn } from "@yekkola/ui/lib/utils";
import { CheckCircle2, XCircle } from "lucide-react";
import { useTranslations } from "next-intl";
import { type FormEvent, type Ref, useEffect, useRef, useState } from "react";

interface QuizLessonProps {
  lessonUid: string;
  facts: NonNullable<LearningViewResourceSectionsItemLessonsItemQuiz>;
  /** After a submission: refresh progress and the quiz facts (a pass completes the lesson). */
  onSubmitted: () => void;
}

type Answers = Record<string, string[]>;

/**
 * A quiz (PRD-06 FR-07): questions without answers → submit → score, pass/fail, the right answers and why.
 * The API decides whether an attempt can start or resume, the timing, and the grading; this displays them.
 */
export function QuizLesson({ lessonUid, facts, onSubmitted }: QuizLessonProps) {
  const t = useTranslations("web.learn.quiz");
  const errors = useTranslations("errors");
  const [attempt, setAttempt] = useState<QuizAttemptResource | null>(null);
  const [answers, setAnswers] = useState<Answers>({});
  const resultHeading = useRef<HTMLDivElement>(null);

  const start = useMutation({
    mutationFn: async () => success(await lessonsQuizAttemptsStore(lessonUid)).data,
    onSuccess: (data) => {
      setAttempt(data);
      setAnswers(loadDraft(data.id));
    },
  });
  const submit = useMutation({
    mutationFn: async () => success(await quizAttemptsSubmit(attempt!.id, { answers })).data,
    onSuccess: (data) => {
      clearDraft(data.id);
      setAttempt(data);
      onSubmitted();
      requestAnimationFrame(() => resultHeading.current?.focus());
    },
  });

  const errorText = (error: unknown) =>
    error instanceof ApiError ? (error.body.message ?? errors("generic")) : errors("network");

  // Before (or between) attempts: the quiz facts and the action the API allows.
  if (!attempt) {
    const label = facts.open_attempt_id ? t("resume") : facts.attempts_used > 0 ? t("retake") : t("start");

    return (
      <div className="grid gap-4">
        <ul className="grid gap-1 text-sm text-muted-foreground">
          <li>{t("facts", { questions: facts.question_count, pass: facts.pass_mark_pct })}</li>
          {facts.time_limit_seconds ? (
            <li>{t("timeLimit", { minutes: Math.ceil(facts.time_limit_seconds / 60) })}</li>
          ) : null}
          <li>
            {t("attempts", {
              used: facts.attempts_used,
              max: facts.max_attempts === null ? "null" : String(facts.max_attempts),
            })}
          </li>
          {facts.best_score_pct !== null ? <li>{t("best", { score: facts.best_score_pct })}</li> : null}
        </ul>
        {facts.passed ? (
          <p className="inline-flex items-center gap-2 font-semibold text-success">
            <CheckCircle2 aria-hidden className="size-5" />
            {t("passed")}
          </p>
        ) : null}
        {facts.can_start ? (
          <div>
            <Button onClick={() => start.mutate()} disabled={start.isPending} aria-busy={start.isPending}>
              {label}
            </Button>
          </div>
        ) : (
          <p className="text-sm">{t("noAttemptsLeft")}</p>
        )}
        {start.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {errorText(start.error)}
          </p>
        ) : null}
      </div>
    );
  }

  if (attempt.submitted_at) {
    return (
      <QuizResults
        attempt={attempt}
        headingRef={resultHeading}
        canRetake={facts.can_start}
        onRetake={() => start.mutate()}
        retaking={start.isPending}
      />
    );
  }

  const unanswered = attempt.questions.filter((q) => !(answers[q.id]?.length ?? 0)).length;
  const choose = (questionId: string, next: string[]) => {
    setAnswers((prev) => {
      const updated = { ...prev, [questionId]: next };
      saveDraft(attempt.id, updated);
      return updated;
    });
  };

  return (
    <form
      className="grid gap-6"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (!submit.isPending) submit.mutate();
      }}
    >
      {attempt.seconds_left !== null ? (
        <Countdown
          secondsLeft={attempt.seconds_left}
          // Submit on time: past the time limit (and its short grace) answers no longer count.
          onTimeUp={() => {
            if (!submit.isPending && !submit.isSuccess) submit.mutate();
          }}
        />
      ) : null}
      <ol className="grid gap-6">
        {attempt.questions.map((question, index) => {
          const multiple = question.type === "multiple";
          const chosen = answers[question.id] ?? [];

          return (
            <li key={question.id}>
              <fieldset className="grid gap-3 rounded-lg border p-4">
                <legend className="grid gap-1 px-1">
                  <span className="text-sm text-muted-foreground">
                    {t("question", { n: index + 1, total: attempt.questions.length })}
                  </span>
                  <span className="font-semibold whitespace-pre-line">{question.prompt}</span>
                </legend>
                <p className="text-xs text-muted-foreground">{multiple ? t("chooseMany") : t("chooseOne")}</p>
                <div className="grid gap-2">
                  {question.options.map((option) => (
                    <label
                      key={option.id}
                      className="flex cursor-pointer items-start gap-3 rounded-md border p-3 has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-2 has-focus-visible:ring-ring"
                    >
                      <input
                        type={multiple ? "checkbox" : "radio"}
                        name={question.id}
                        value={option.id}
                        checked={chosen.includes(option.id)}
                        className="mt-1 size-4 accent-primary"
                        onChange={(e) =>
                          choose(
                            question.id,
                            multiple
                              ? e.target.checked
                                ? [...chosen, option.id]
                                : chosen.filter((id) => id !== option.id)
                              : [option.id],
                          )
                        }
                      />
                      <span>{option.text}</span>
                    </label>
                  ))}
                </div>
              </fieldset>
            </li>
          );
        })}
      </ol>
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" size="lg" disabled={submit.isPending} aria-busy={submit.isPending}>
          {submit.isPending ? t("submitting") : t("submit")}
        </Button>
        {unanswered > 0 ? (
          <span className="text-sm text-muted-foreground">{t("unanswered", { count: unanswered })}</span>
        ) : null}
      </div>
      {submit.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(submit.error)}
        </p>
      ) : null}
    </form>
  );
}

/** Draft answers survive a reload or a trip to another lesson (per attempt, this tab only). */
function draftKey(attemptId: string): string {
  return `yk-quiz-draft:${attemptId}`;
}

function loadDraft(attemptId: string): Answers {
  try {
    return JSON.parse(sessionStorage.getItem(draftKey(attemptId)) ?? "{}") as Answers;
  } catch {
    return {};
  }
}

function saveDraft(attemptId: string, answers: Answers): void {
  try {
    sessionStorage.setItem(draftKey(attemptId), JSON.stringify(answers));
  } catch {
    // Storage full or disabled: the attempt still works, only the draft isn't kept.
  }
}

function clearDraft(attemptId: string): void {
  try {
    sessionStorage.removeItem(draftKey(attemptId));
  } catch {
    // ignore
  }
}

/** Counts down from the server's `seconds_left` (device clocks can be off); fires `onTimeUp` once. */
function Countdown({ secondsLeft, onTimeUp }: { secondsLeft: number; onTimeUp: () => void }) {
  const t = useTranslations("web.learn.quiz");
  const [deadline] = useState(() => performance.now() + secondsLeft * 1000);
  const [left, setLeft] = useState(secondsLeft);
  const fired = useRef(false);
  const timeUp = useRef(onTimeUp);
  useEffect(() => {
    timeUp.current = onTimeUp;
  }, [onTimeUp]);

  useEffect(() => {
    const tick = () => {
      const remaining = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
      setLeft(remaining);
      if (remaining === 0 && !fired.current) {
        fired.current = true;
        timeUp.current();
      }
    };
    tick();
    const timer = window.setInterval(tick, 1000);
    return () => window.clearInterval(timer);
  }, [deadline]);

  const time = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, "0")}`;
  const lastMinute = left <= 60;

  return (
    <>
      <p
        className={cn(
          "sticky top-2 z-10 w-fit rounded-md border bg-background px-3 py-1 font-bold tabular-nums shadow-sm",
          lastMinute && "text-destructive",
        )}
      >
        {t("timeLeft", { time })}
      </p>
      {/* Announced once, when the last minute starts — not every second. */}
      <p className="sr-only" aria-live="polite">
        {lastMinute ? t("timeLeft", { time: "1:00" }) : ""}
      </p>
    </>
  );
}

/** The learning moment for exam prep (PRD-06 §6): every question with my answer, the right one, and why. */
function QuizResults({
  attempt,
  headingRef,
  canRetake,
  onRetake,
  retaking,
}: {
  attempt: QuizAttemptResource;
  headingRef: Ref<HTMLDivElement>;
  canRetake: boolean;
  onRetake: () => void;
  retaking: boolean;
}) {
  const t = useTranslations("web.learn.quiz");
  const score = attempt.score_pct ?? 0;

  return (
    <div className="grid gap-6">
      <div
        ref={headingRef}
        tabIndex={-1}
        role="status"
        className={cn(
          "rounded-lg border-2 p-4 font-bold focus:outline-none",
          attempt.passed ? "border-success text-success" : "border-destructive text-destructive",
        )}
      >
        {attempt.timed_out
          ? t("timedOut")
          : attempt.passed
            ? t("resultPassed", { score })
            : t("resultFailed", { score, pass: attempt.pass_mark_pct })}
      </div>
      <ol className="grid gap-4">
        {attempt.questions.map((question, index) => {
          const chosen = question.chosen ?? [];
          const correct = question.correct ?? [];

          return (
            <li key={question.id} className="grid gap-2 rounded-lg border p-4">
              <p className="flex items-start gap-2 font-semibold">
                {question.answered_correctly ? (
                  <CheckCircle2 aria-label={t("correct")} className="mt-0.5 size-5 shrink-0 text-success" />
                ) : (
                  <XCircle aria-label={t("incorrect")} className="mt-0.5 size-5 shrink-0 text-destructive" />
                )}
                <span>
                  {index + 1}. {question.prompt}
                </span>
              </p>
              <ul className="grid gap-1 text-sm">
                {question.options.map((option) => {
                  const isCorrect = correct.includes(option.id);
                  const isChosen = chosen.includes(option.id);
                  return (
                    <li
                      key={option.id}
                      className={cn(
                        "rounded px-2 py-1",
                        isCorrect && "bg-success/10 font-semibold",
                        isChosen && !isCorrect && "bg-destructive/10 line-through",
                      )}
                    >
                      {option.text}
                      {isChosen ? (
                        <span className="ml-2 text-xs text-muted-foreground">({t("yourAnswer")})</span>
                      ) : null}
                      {isCorrect ? (
                        <span className="ml-2 text-xs text-success">({t("correctAnswer")})</span>
                      ) : null}
                    </li>
                  );
                })}
              </ul>
              {question.explanation ? (
                <p className="rounded-md bg-accent px-3 py-2 text-sm text-accent-foreground">
                  <span className="font-bold">{t("explanation")} </span>
                  {question.explanation}
                </p>
              ) : null}
            </li>
          );
        })}
      </ol>
      {canRetake ? (
        <div>
          <Button onClick={onRetake} disabled={retaking} aria-busy={retaking}>
            {t("retake")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}
