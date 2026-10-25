"use client";

import { Accessibility } from "@dnd-kit/dom";
import { move } from "@dnd-kit/helpers";
import { DragDropProvider } from "@dnd-kit/react";
import { useSortable } from "@dnd-kit/react/sortable";
import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import {
  professorDraftLessonsStore,
  professorDraftReorder,
  professorDraftSectionsDestroy,
  professorDraftSectionsStore,
  professorDraftSectionsUpdate,
} from "@yekkola/api-client/endpoints/draft";
import type { CourseVersionResource, LessonType, StudioLessonResource } from "@yekkola/api-client/model";
import { formatDuration, type Locale } from "@yekkola/i18n";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { Badge } from "@yekkola/ui/components/badge";
import { Button } from "@yekkola/ui/components/button";
import { Input } from "@yekkola/ui/components/input";
import { Label } from "@yekkola/ui/components/label";
import { cn } from "@yekkola/ui/lib/utils";
import {
  ChevronDown,
  ChevronUp,
  FileText,
  GripVertical,
  Headphones,
  ListChecks,
  ListTree,
  Plus,
  Trash2,
  Video,
} from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { type FormEvent, type ReactNode, useId, useMemo, useRef, useState } from "react";

import { useErrorText, useRefreshCourse } from "../hooks";
import { selectClass } from "../styles";
import { useUpload } from "../upload-store";
import { DraftGate } from "./draft-gate";
import { LessonEditor } from "./lesson-editor";

const LESSON_TYPES: LessonType[] = ["video", "audio", "document", "quiz"];
const TYPE_ICONS: Record<string, ReactNode> = {
  video: <Video aria-hidden className="size-4" />,
  audio: <Headphones aria-hidden className="size-4" />,
  document: <FileText aria-hidden className="size-4" />,
  quiz: <ListChecks aria-hidden className="size-4" />,
};
const HANDLE_CLASS =
  "cursor-grab touch-none rounded p-1 text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-wait disabled:opacity-50";
/** Sections take drops only when nothing more specific (a lesson) is under the pointer (CollisionPriority.Low). */
const SECTION_COLLISION_PRIORITY = 1;

/** Section order and each section's lesson order, by id — what the reorder endpoint takes. */
interface Layout {
  sections: string[];
  lessons: Record<string, string[]>;
}

function layoutOf(draft: CourseVersionResource): Layout {
  return {
    sections: draft.sections.map((s) => s.id),
    lessons: Object.fromEntries(draft.sections.map((s) => [s.id, s.lessons.map((l) => l.id)])),
  };
}

const sameLayout = (a: Layout, b: Layout) => JSON.stringify(a) === JSON.stringify(b);

/** The part of a drag event the announcements read. */
interface Announced {
  operation: { source: { id: string | number } | null; target: { id: string | number } | null };
  canceled?: boolean;
}

/** Curriculum tab (PRD-03 FR-03): sections and lessons, drag-and-drop order, each change saved at once. */
export function CurriculumTab({ courseId }: { courseId: string }) {
  return (
    <DraftGate courseId={courseId}>
      {(draft, editable) => <Curriculum courseId={courseId} draft={draft} editable={editable} />}
    </DraftGate>
  );
}

function Curriculum({
  courseId,
  draft,
  editable,
}: {
  courseId: string;
  draft: CourseVersionResource;
  editable: boolean;
}) {
  const t = useTranslations("web.studio");
  const dnd = useTranslations("web.studio.dnd");
  const errorText = useErrorText();
  const refresh = useRefreshCourse(courseId);
  const server = useMemo(() => layoutOf(draft), [draft]);
  // While dragging (and until the new order is saved) the editor shows its own copy of the order.
  const [local, setLocal] = useState<Layout | null>(null);
  const localRef = useRef<Layout | null>(null);
  const [openLesson, setOpenLesson] = useState<string | null>(null);
  const layout = local ?? server;

  const setLayout = (next: Layout | null) => {
    localRef.current = next;
    setLocal(next);
  };

  const sectionsById = useMemo(() => new Map(draft.sections.map((s) => [s.id, s])), [draft]);
  const lessonsById = useMemo(
    () => new Map(draft.sections.flatMap((s) => s.lessons.map((l) => [l.id, l] as const))),
    [draft],
  );
  const titles = useMemo(
    () =>
      new Map([
        ...draft.sections.map((s) => [s.id, s.title] as const),
        ...[...lessonsById.values()].map((l) => [l.id, l.title] as const),
      ]),
    [draft, lessonsById],
  );

  const reorder = useMutation({
    mutationFn: async (next: Layout) =>
      success(
        await professorDraftReorder(courseId, {
          sections: next.sections.map((id) => ({ id, lessons: next.lessons[id] ?? [] })),
        }),
      ),
    onSettled: async () => {
      await refresh();
      setLayout(null);
    },
  });

  const addSection = useMutation({
    mutationFn: async (title: string) => success(await professorDraftSectionsStore(courseId, { title })),
    onSuccess: refresh,
  });

  // Screen readers hear the item's title and where it lands, in the professor's language.
  const plugins = useMemo(
    () => [
      Accessibility.configure({
        screenReaderInstructions: { draggable: dnd("instructions") },
        announcements: {
          dragstart: ({ operation }: Announced) =>
            operation.source
              ? dnd("picked", { item: titles.get(String(operation.source.id)) ?? "" })
              : undefined,
          dragover: ({ operation }: Announced) =>
            operation.source && operation.target
              ? dnd("over", {
                  item: titles.get(String(operation.source.id)) ?? "",
                  target: titles.get(String(operation.target.id)) ?? "",
                })
              : undefined,
          dragend: ({ operation, canceled }: Announced) =>
            operation.source
              ? canceled
                ? dnd("canceled")
                : dnd("dropped", { item: titles.get(String(operation.source.id)) ?? "" })
              : undefined,
        },
      }),
    ],
    [dnd, titles],
  );

  return (
    <div className="grid gap-4">
      {editable ? <p className="text-sm text-muted-foreground">{t("curriculumIntro")}</p> : null}

      {reorder.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(reorder.error)}
        </p>
      ) : null}

      {layout.sections.length === 0 ? (
        <EmptyState icon={<ListTree />} title={t("noSections")} description={t("noSectionsHint")} />
      ) : (
        <DragDropProvider
          plugins={(defaults) => [...defaults, ...plugins]}
          onDragStart={() => setLayout(layout)}
          onDragOver={(event) => {
            const current = localRef.current;
            if (!current || event.operation.source?.type !== "lesson") return;
            setLayout({ ...current, lessons: move(current.lessons, event) });
          }}
          onDragEnd={(event) => {
            const current = localRef.current;
            if (event.canceled || !current) {
              setLayout(null);
              return;
            }
            const next =
              event.operation.source?.type === "section"
                ? { ...current, sections: move(current.sections, event) }
                : current;
            if (sameLayout(next, server)) {
              setLayout(null);
              return;
            }
            setLayout(next);
            reorder.mutate(next);
          }}
        >
          <ol className="grid gap-4">
            {layout.sections.map((sectionId, index) => {
              const section = sectionsById.get(sectionId);
              if (!section) return null;
              return (
                <SectionItem
                  key={sectionId}
                  courseId={courseId}
                  id={sectionId}
                  index={index}
                  title={section.title}
                  editable={editable}
                  busy={reorder.isPending}
                  lessonCount={(layout.lessons[sectionId] ?? []).length}
                  onLessonAdded={setOpenLesson}
                >
                  {(layout.lessons[sectionId] ?? []).map((lessonId, lessonIndex) => {
                    const lesson = lessonsById.get(lessonId);
                    if (!lesson) return null;
                    const props = {
                      courseId,
                      lesson,
                      editable,
                      open: openLesson === lessonId,
                      onToggle: () => setOpenLesson(openLesson === lessonId ? null : lessonId),
                      onDeleted: () => setOpenLesson(null),
                    };
                    // An open lesson is too tall to move comfortably (one key press barely moves it): fold it first.
                    return editable && !props.open ? (
                      <DraggableLesson
                        key={lessonId}
                        {...props}
                        index={lessonIndex}
                        sectionId={sectionId}
                        busy={reorder.isPending}
                      />
                    ) : (
                      <LessonCard key={lessonId} {...props} />
                    );
                  })}
                </SectionItem>
              );
            })}
          </ol>
        </DragDropProvider>
      )}

      {editable ? (
        <AddSectionForm
          pending={addSection.isPending}
          error={addSection.isError ? errorText(addSection.error) : null}
          onAdd={(title) => addSection.mutateAsync(title).then(() => undefined)}
        />
      ) : null}
    </div>
  );
}

interface SectionProps {
  courseId: string;
  id: string;
  index: number;
  title: string;
  editable: boolean;
  lessonCount: number;
  onLessonAdded: (lessonId: string) => void;
  children: ReactNode;
}

/**
 * dnd-kit puts its drag attributes (role, tabindex, aria-disabled) on the handle, or on the whole item when there
 * is none — so a sortable is only mounted together with its handle; read-only items are plain list items.
 */
function SectionItem({ busy, ...props }: SectionProps & { busy: boolean }) {
  return props.editable ? <SortableSection {...props} busy={busy} /> : <SectionCard {...props} />;
}

function SortableSection({ busy, ...props }: SectionProps & { busy: boolean }) {
  const t = useTranslations("web.studio");
  const { ref, handleRef, isDragging } = useSortable({
    id: props.id,
    index: props.index,
    type: "section",
    accept: ["section", "lesson"],
    collisionPriority: SECTION_COLLISION_PRIORITY,
    disabled: busy,
  });

  return (
    <SectionCard
      {...props}
      itemRef={ref}
      dragging={isDragging}
      handle={
        <button
          ref={handleRef}
          type="button"
          disabled={busy}
          className={HANDLE_CLASS}
          aria-label={t("dragSection", { title: props.title })}
        >
          <GripVertical aria-hidden className="size-5" />
        </button>
      }
    />
  );
}

function SectionCard({
  courseId,
  id,
  index,
  title,
  editable,
  lessonCount,
  onLessonAdded,
  children,
  itemRef,
  handle,
  dragging = false,
}: SectionProps & { itemRef?: (element: Element | null) => void; handle?: ReactNode; dragging?: boolean }) {
  const t = useTranslations("web.studio");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const refresh = useRefreshCourse(courseId);
  const headingId = useId();
  const [name, setName] = useState(title);

  const rename = useMutation({
    mutationFn: async (next: string) =>
      success(await professorDraftSectionsUpdate(courseId, id, { title: next })),
    onSuccess: refresh,
  });
  const remove = useMutation({
    mutationFn: () => professorDraftSectionsDestroy(courseId, id),
    onSuccess: refresh,
  });
  const commitName = () => {
    const next = name.trim();
    if (next && next !== title && !rename.isPending) rename.mutate(next);
    if (!next) setName(title);
  };

  return (
    <li
      ref={itemRef}
      aria-labelledby={headingId}
      className={cn("grid gap-3 rounded-xl border bg-background p-3 sm:p-4", dragging && "opacity-60")}
    >
      <div className="flex items-center gap-2">
        {handle}
        <h3 id={headingId} className="sr-only">
          {t("sectionNumber", { number: index + 1, title })}
        </h3>
        <Input
          value={name}
          maxLength={120}
          disabled={!editable}
          aria-label={t("sectionTitle", { number: index + 1 })}
          className="font-bold"
          onChange={(e) => setName(e.target.value)}
          onBlur={commitName}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              commitName();
            }
          }}
        />
        {editable ? (
          <ConfirmDialog
            trigger={
              <Button variant="ghost" size="icon" aria-label={t("deleteSection", { title })}>
                <Trash2 aria-hidden />
              </Button>
            }
            title={t("deleteSectionConfirm", { title })}
            description={lessonCount > 0 ? t("deleteSectionHint", { count: lessonCount }) : undefined}
            confirmLabel={t("deleteSectionShort")}
            cancelLabel={common("cancel")}
            destructive
            onConfirm={async () => {
              await remove.mutateAsync();
            }}
          />
        ) : null}
      </div>
      {rename.isError || remove.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(rename.error ?? remove.error)}
        </p>
      ) : null}

      <ol className="grid min-h-12 gap-2 rounded-lg border border-dashed p-2">
        {children}
        {lessonCount === 0 ? (
          <li className="p-2 text-sm text-muted-foreground">{t("emptySection")}</li>
        ) : null}
      </ol>

      {editable ? <AddLessonForm courseId={courseId} sectionId={id} onAdded={onLessonAdded} /> : null}
    </li>
  );
}

interface LessonProps {
  courseId: string;
  lesson: StudioLessonResource;
  editable: boolean;
  open: boolean;
  onToggle: () => void;
  onDeleted: () => void;
}

function DraggableLesson({
  index,
  sectionId,
  busy,
  ...props
}: LessonProps & { index: number; sectionId: string; busy: boolean }) {
  const t = useTranslations("web.studio");
  const { ref, handleRef, isDragging } = useSortable({
    id: props.lesson.id,
    index,
    group: sectionId,
    type: "lesson",
    accept: "lesson",
    disabled: busy,
  });

  return (
    <LessonCard
      {...props}
      itemRef={ref}
      dragging={isDragging}
      handle={
        <button
          ref={handleRef}
          type="button"
          disabled={busy}
          className={HANDLE_CLASS}
          aria-label={t("dragLesson", { title: props.lesson.title })}
        >
          <GripVertical aria-hidden className="size-4" />
        </button>
      }
    />
  );
}

function LessonCard({
  courseId,
  lesson,
  editable,
  open,
  onToggle,
  onDeleted,
  itemRef,
  handle,
  dragging = false,
}: LessonProps & { itemRef?: (element: Element | null) => void; handle?: ReactNode; dragging?: boolean }) {
  const t = useTranslations("web.studio");
  const catalog = useTranslations("web.catalog");
  const locale = useLocale() as Locale;
  const upload = useUpload(lesson.id);
  const panelId = useId();
  const uploading =
    upload?.status === "starting" || upload?.status === "uploading" || upload?.status === "offline";

  return (
    <li
      ref={itemRef}
      className={cn("overflow-hidden rounded-lg border bg-background", dragging && "opacity-60")}
    >
      <div className="flex items-center gap-2 p-2">
        {/* Same indent with or without a handle (an open lesson is folded before moving). */}
        {handle ?? (editable ? <span aria-hidden className="size-6 shrink-0" /> : null)}
        <span className="text-muted-foreground" title={catalog(`lessonTypes.${lesson.type as LessonType}`)}>
          {TYPE_ICONS[lesson.type]}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate font-semibold">{lesson.title}</span>
          <span className="flex flex-wrap gap-x-2 text-xs text-muted-foreground">
            <span>{catalog(`lessonTypes.${lesson.type as LessonType}`)}</span>
            {lesson.duration_seconds ? <span>{formatDuration(lesson.duration_seconds, locale)}</span> : null}
          </span>
        </span>
        <span className="hidden flex-wrap gap-1 sm:flex">
          {lesson.is_preview ? <Badge variant="accent">{t("previewBadge")}</Badge> : null}
          {uploading ? (
            <Badge variant="info">{t("contentUploading")}</Badge>
          ) : lesson.is_content_ready ? (
            <Badge variant="success">{t("contentReady")}</Badge>
          ) : lesson.media?.status === "processing" ? (
            <Badge variant="info">{t("contentProcessing")}</Badge>
          ) : (
            <Badge variant="warning">{t("contentMissing")}</Badge>
          )}
        </span>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          aria-expanded={open}
          aria-controls={panelId}
          onClick={onToggle}
        >
          {editable ? t("editLesson") : t("viewLesson")}
          {open ? <ChevronUp aria-hidden /> : <ChevronDown aria-hidden />}
        </Button>
      </div>
      <div id={panelId} hidden={!open}>
        {open ? (
          <LessonEditor
            key={`${lesson.id}:${lesson.title}:${lesson.is_preview}`}
            courseId={courseId}
            lesson={lesson}
            editable={editable}
            onDeleted={onDeleted}
          />
        ) : null}
      </div>
    </li>
  );
}

function AddLessonForm({
  courseId,
  sectionId,
  onAdded,
}: {
  courseId: string;
  sectionId: string;
  onAdded: (lessonId: string) => void;
}) {
  const t = useTranslations("web.studio");
  const catalog = useTranslations("web.catalog");
  const errorText = useErrorText();
  const refresh = useRefreshCourse(courseId);
  const id = useId();
  const [type, setType] = useState<LessonType>("video");
  const [title, setTitle] = useState("");

  const add = useMutation({
    mutationFn: async () =>
      success(
        await professorDraftLessonsStore(courseId, { section_id: sectionId, type, title: title.trim() }),
      ).data,
    onSuccess: async (lesson) => {
      setTitle("");
      await refresh();
      onAdded(lesson.id);
    },
  });

  return (
    <form
      className="grid gap-2 sm:grid-cols-[10rem_1fr_auto] sm:items-end"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        if (title.trim().length >= 2 && !add.isPending) add.mutate();
      }}
    >
      <div className="grid gap-1">
        <Label htmlFor={`${id}-type`} className="text-xs">
          {t("lessonType")}
        </Label>
        <select
          id={`${id}-type`}
          className={selectClass}
          value={type}
          onChange={(e) => setType(e.target.value as LessonType)}
        >
          {LESSON_TYPES.map((value) => (
            <option key={value} value={value}>
              {catalog(`lessonTypes.${value}`)}
            </option>
          ))}
        </select>
      </div>
      <div className="grid gap-1">
        <Label htmlFor={`${id}-title`} className="text-xs">
          {t("fields.lessonTitle")}
        </Label>
        <Input id={`${id}-title`} value={title} maxLength={120} onChange={(e) => setTitle(e.target.value)} />
      </div>
      <Button
        type="submit"
        variant="outline"
        disabled={title.trim().length < 2 || add.isPending}
        aria-busy={add.isPending}
      >
        <Plus aria-hidden />
        {t("addLesson")}
      </Button>
      {add.isError ? (
        <p role="alert" className="text-sm text-destructive sm:col-span-3">
          {errorText(add.error)}
        </p>
      ) : null}
    </form>
  );
}

function AddSectionForm({
  pending,
  error,
  onAdd,
}: {
  pending: boolean;
  error: string | null;
  onAdd: (title: string) => Promise<void>;
}) {
  const t = useTranslations("web.studio");
  const [title, setTitle] = useState("");

  return (
    <form
      className="grid gap-2 rounded-xl border border-dashed bg-background p-3 sm:grid-cols-[1fr_auto] sm:items-end sm:p-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        const next = title.trim();
        if (next && !pending)
          void onAdd(next).then(
            () => setTitle(""),
            () => undefined,
          );
      }}
    >
      <div className="grid gap-1">
        <Label htmlFor="new-section-title">{t("newSection")}</Label>
        <Input
          id="new-section-title"
          value={title}
          maxLength={120}
          onChange={(e) => setTitle(e.target.value)}
        />
      </div>
      <Button type="submit" disabled={!title.trim() || pending} aria-busy={pending}>
        <Plus aria-hidden />
        {t("addSection")}
      </Button>
      {error ? (
        <p role="alert" className="text-sm text-destructive sm:col-span-2">
          {error}
        </p>
      ) : null}
    </form>
  );
}
