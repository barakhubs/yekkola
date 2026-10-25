"use client";

import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { professorDraftLessonsQuiz } from "@yekkola/api-client/endpoints/draft";
import type { QuestionType, StudioLessonResource } from "@yekkola/api-client/model";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { Textarea } from "@yekkola/ui/components/textarea";
import { ArrowDown, ArrowUp, Plus, Trash2, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { type FormEvent, useId, useRef, useState } from "react";

import { fieldErrorsOf, useAuthoringOptions, useErrorText, useRefreshCourse } from "../hooks";
import { selectClass } from "../styles";

interface DraftOption {
  key: string;
  text: string;
  is_correct: boolean;
}

interface DraftQuestion {
  key: string;
  type: QuestionType;
  prompt: string;
  explanation: string;
  points: number;
  options: DraftOption[];
}

const TYPES: QuestionType[] = ["single", "multiple", "true_false"];

let nextKey = 0;
const newKey = () => `k${++nextKey}`;

/**
 * Quiz builder (PRD-03 FR-07): single/multiple choice and true/false questions with options, the right
 * answers, an explanation and points; pass mark, optional time limit and attempts, shuffle. Saved as a whole;
 * the API validates (e.g. one right answer for single choice) and its messages show next to each question.
 */
export function QuizBuilder({
  courseId,
  lesson,
  editable,
}: {
  courseId: string;
  lesson: StudioLessonResource;
  editable: boolean;
}) {
  const t = useTranslations("web.studio.quiz");
  const studio = useTranslations("web.studio");
  const errorText = useErrorText();
  const refresh = useRefreshCourse(courseId);
  const options = useAuthoringOptions();
  const quiz = lesson.quiz;
  const formId = useId();
  const lastQuestion = useRef<HTMLTextAreaElement>(null);

  const [passMark, setPassMark] = useState(
    String(quiz?.pass_mark_pct ?? options.data?.default_pass_mark_pct ?? ""),
  );
  const [minutes, setMinutes] = useState(
    quiz?.time_limit_seconds ? String(quiz.time_limit_seconds / 60) : "",
  );
  const [attempts, setAttempts] = useState(quiz?.max_attempts ? String(quiz.max_attempts) : "");
  const [shuffle, setShuffle] = useState(quiz?.shuffle_questions ?? false);
  const [questions, setQuestions] = useState<DraftQuestion[]>(() =>
    (quiz?.questions ?? []).map((q) => ({
      key: newKey(),
      type: q.type as QuestionType,
      prompt: q.prompt,
      explanation: q.explanation ?? "",
      points: q.points,
      options: q.options.map((o) => ({ key: newKey(), text: o.text, is_correct: o.is_correct })),
    })),
  );
  const [dirty, setDirty] = useState(false);

  const save = useMutation({
    mutationFn: async () =>
      success(
        await professorDraftLessonsQuiz(courseId, lesson.id, {
          pass_mark_pct: Number(passMark),
          time_limit_seconds: minutes ? Math.round(Number(minutes) * 60) : null,
          max_attempts: attempts ? Number(attempts) : null,
          shuffle_questions: shuffle,
          questions: questions.map((q) => ({
            type: q.type,
            prompt: q.prompt.trim(),
            explanation: q.explanation.trim() || null,
            points: q.points,
            options: q.options.map((o) => ({ text: o.text.trim(), is_correct: o.is_correct })),
          })),
        }),
      ),
    onSuccess: async () => {
      setDirty(false);
      await refresh();
    },
  });
  const errors = fieldErrorsOf(save.error);

  const change = (next: DraftQuestion[]) => {
    setQuestions(next);
    setDirty(true);
    if (save.isSuccess) save.reset();
  };
  const update = (index: number, patch: Partial<DraftQuestion>) =>
    change(questions.map((q, i) => (i === index ? { ...q, ...patch } : q)));
  const trueFalse = (): DraftOption[] => [
    { key: newKey(), text: t("true"), is_correct: true },
    { key: newKey(), text: t("false"), is_correct: false },
  ];
  const addQuestion = () => {
    change([
      ...questions,
      {
        key: newKey(),
        type: "single",
        prompt: "",
        explanation: "",
        points: 1,
        options: [
          { key: newKey(), text: "", is_correct: true },
          { key: newKey(), text: "", is_correct: false },
        ],
      },
    ]);
    requestAnimationFrame(() => lastQuestion.current?.focus());
  };
  const move = (index: number, by: -1 | 1) => {
    const next = [...questions];
    const [item] = next.splice(index, 1);
    if (item) next.splice(index + by, 0, item);
    change(next);
  };
  const setting = (set: (v: string) => void) => (value: string) => {
    set(value);
    setDirty(true);
    if (save.isSuccess) save.reset();
  };

  return (
    <form
      id={formId}
      className="grid gap-5"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (!save.isPending) save.mutate();
      }}
    >
      <fieldset className="grid gap-4 sm:grid-cols-3" disabled={!editable}>
        <legend className="sr-only">{t("settings")}</legend>
        <div className="grid gap-2">
          <Label htmlFor={`${formId}-pass`}>{t("passMark")}</Label>
          <Input
            id={`${formId}-pass`}
            type="number"
            inputMode="numeric"
            min={1}
            max={100}
            value={passMark}
            aria-invalid={errors.pass_mark_pct ? true : undefined}
            onChange={(e) => setting(setPassMark)(e.target.value)}
          />
          {errors.pass_mark_pct ? <p className="text-sm text-destructive">{errors.pass_mark_pct}</p> : null}
        </div>
        <div className="grid gap-2">
          <Label htmlFor={`${formId}-time`}>{t("timeLimit")}</Label>
          <Input
            id={`${formId}-time`}
            type="number"
            inputMode="numeric"
            min={1}
            max={240}
            placeholder={t("none")}
            value={minutes}
            aria-invalid={errors.time_limit_seconds ? true : undefined}
            onChange={(e) => setting(setMinutes)(e.target.value)}
          />
          {errors.time_limit_seconds ? (
            <p className="text-sm text-destructive">{errors.time_limit_seconds}</p>
          ) : null}
        </div>
        <div className="grid gap-2">
          <Label htmlFor={`${formId}-attempts`}>{t("maxAttempts")}</Label>
          <Input
            id={`${formId}-attempts`}
            type="number"
            inputMode="numeric"
            min={1}
            max={100}
            placeholder={t("unlimited")}
            value={attempts}
            aria-invalid={errors.max_attempts ? true : undefined}
            onChange={(e) => setting(setAttempts)(e.target.value)}
          />
          {errors.max_attempts ? <p className="text-sm text-destructive">{errors.max_attempts}</p> : null}
        </div>
        <label className="flex items-center gap-2 text-sm sm:col-span-3">
          <input
            type="checkbox"
            className="size-4 accent-primary"
            checked={shuffle}
            onChange={(e) => {
              setShuffle(e.target.checked);
              setDirty(true);
            }}
          />
          {t("shuffle")}
        </label>
      </fieldset>

      <ol className="grid gap-4">
        {questions.map((q, index) => {
          const prefix = `questions.${index}`;
          const questionErrors = Object.entries(errors).filter(
            ([key]) => key === prefix || key.startsWith(`${prefix}.`),
          );
          const single = q.type !== "multiple";
          const qid = `${formId}-q${q.key}`;

          return (
            <li key={q.key} className="grid gap-3 rounded-lg border p-3 sm:p-4">
              <fieldset className="grid gap-3" disabled={!editable} aria-describedby={`${qid}-errors`}>
                <legend className="text-sm font-bold">{t("question", { number: index + 1 })}</legend>
                <div className="grid gap-3 sm:grid-cols-[1fr_10rem_6rem]">
                  <div className="grid gap-2">
                    <Label htmlFor={`${qid}-prompt`}>{t("prompt")}</Label>
                    <Textarea
                      id={`${qid}-prompt`}
                      ref={index === questions.length - 1 ? lastQuestion : undefined}
                      rows={2}
                      maxLength={2000}
                      value={q.prompt}
                      onChange={(e) => update(index, { prompt: e.target.value })}
                    />
                  </div>
                  <div className="grid content-start gap-2">
                    <Label htmlFor={`${qid}-type`}>{t("type")}</Label>
                    <select
                      id={`${qid}-type`}
                      className={selectClass}
                      value={q.type}
                      onChange={(e) => {
                        const type = e.target.value as QuestionType;
                        update(index, {
                          type,
                          options:
                            type === "true_false"
                              ? trueFalse()
                              : q.type === "true_false"
                                ? [
                                    { key: newKey(), text: "", is_correct: true },
                                    { key: newKey(), text: "", is_correct: false },
                                  ]
                                : type === "single"
                                  ? q.options.map((o, i) => ({
                                      ...o,
                                      is_correct: i === q.options.findIndex((x) => x.is_correct),
                                    }))
                                  : q.options,
                        });
                      }}
                    >
                      {TYPES.map((type) => (
                        <option key={type} value={type}>
                          {t(`types.${type}`)}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="grid content-start gap-2">
                    <Label htmlFor={`${qid}-points`}>{t("points")}</Label>
                    <Input
                      id={`${qid}-points`}
                      type="number"
                      inputMode="numeric"
                      min={1}
                      max={100}
                      value={q.points}
                      onChange={(e) => update(index, { points: Number(e.target.value) || 1 })}
                    />
                  </div>
                </div>

                <fieldset className="grid gap-2">
                  <legend className="mb-1 text-sm font-semibold">
                    {single ? t("optionsSingle") : t("optionsMultiple")}
                  </legend>
                  {q.options.map((option, oi) => (
                    <div key={option.key} className="flex items-center gap-2">
                      <input
                        type={single ? "radio" : "checkbox"}
                        name={`${qid}-correct`}
                        className="size-4 shrink-0 accent-primary"
                        checked={option.is_correct}
                        aria-label={t("markCorrect", { number: oi + 1 })}
                        onChange={(e) =>
                          update(index, {
                            options: q.options.map((o, i) =>
                              single
                                ? { ...o, is_correct: i === oi }
                                : i === oi
                                  ? { ...o, is_correct: e.target.checked }
                                  : o,
                            ),
                          })
                        }
                      />
                      <Input
                        value={option.text}
                        maxLength={500}
                        readOnly={q.type === "true_false"}
                        aria-label={t("optionText", { number: oi + 1 })}
                        onChange={(e) =>
                          update(index, {
                            options: q.options.map((o, i) => (i === oi ? { ...o, text: e.target.value } : o)),
                          })
                        }
                      />
                      {q.type !== "true_false" && q.options.length > 2 ? (
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          aria-label={t("removeOption", { number: oi + 1 })}
                          onClick={() => update(index, { options: q.options.filter((_, i) => i !== oi) })}
                        >
                          <X aria-hidden />
                        </Button>
                      ) : null}
                    </div>
                  ))}
                  {q.type !== "true_false" && q.options.length < 8 ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="w-fit"
                      onClick={() =>
                        update(index, {
                          options: [...q.options, { key: newKey(), text: "", is_correct: false }],
                        })
                      }
                    >
                      <Plus aria-hidden />
                      {t("addOption")}
                    </Button>
                  ) : null}
                </fieldset>

                <div className="grid gap-2">
                  <Label htmlFor={`${qid}-explanation`}>{t("explanation")}</Label>
                  <Textarea
                    id={`${qid}-explanation`}
                    rows={2}
                    maxLength={4000}
                    value={q.explanation}
                    aria-describedby={`${qid}-explanation-hint`}
                    onChange={(e) => update(index, { explanation: e.target.value })}
                  />
                  <p id={`${qid}-explanation-hint`} className="text-xs text-muted-foreground">
                    {t("explanationHint")}
                  </p>
                </div>
              </fieldset>

              <div id={`${qid}-errors`}>
                {questionErrors.map(([key, message]) => (
                  <p key={key} className="text-sm text-destructive">
                    {message}
                  </p>
                ))}
              </div>

              {editable ? (
                <div className="flex flex-wrap gap-2">
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={index === 0}
                    onClick={() => move(index, -1)}
                    aria-label={t("moveUp", { number: index + 1 })}
                  >
                    <ArrowUp aria-hidden />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={index === questions.length - 1}
                    onClick={() => move(index, 1)}
                    aria-label={t("moveDown", { number: index + 1 })}
                  >
                    <ArrowDown aria-hidden />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => change(questions.filter((_, i) => i !== index))}
                  >
                    <Trash2 aria-hidden />
                    {t("removeQuestion")}
                  </Button>
                </div>
              ) : null}
            </li>
          );
        })}
      </ol>

      {questions.length === 0 ? <p className="text-sm text-muted-foreground">{t("empty")}</p> : null}
      {errors.questions ? <p className="text-sm text-destructive">{errors.questions}</p> : null}

      {editable ? (
        <div className="flex flex-wrap items-center gap-3">
          <Button type="button" variant="outline" onClick={addQuestion} disabled={questions.length >= 200}>
            <Plus aria-hidden />
            {t("addQuestion")}
          </Button>
          <Button type="submit" disabled={!dirty || save.isPending} aria-busy={save.isPending}>
            {t("save")}
          </Button>
          {save.isError ? (
            <p role="alert" className="text-sm text-destructive">
              {Object.keys(errors).length > 0 ? studio("fixErrors") : errorText(save.error)}
            </p>
          ) : (
            <p role="status" className="text-sm text-muted-foreground">
              {save.isSuccess && !dirty ? studio("saved") : ""}
            </p>
          )}
        </div>
      ) : null}
    </form>
  );
}
