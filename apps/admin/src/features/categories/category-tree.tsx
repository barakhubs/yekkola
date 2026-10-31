"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  adminCategoriesDestroy,
  adminCategoriesOrder,
  adminCategoriesStore,
  adminCategoriesUpdate,
  getAdminCategoriesIndexQueryKey,
  useAdminCategoriesIndex,
} from "@yekkola/api-client/endpoints/category";
import type { AdminCategoryResource } from "@yekkola/api-client/model";
import { ConfirmDialog } from "@yekkola/ui/blocks/confirm-dialog";
import { PageHeader } from "@yekkola/ui/blocks/page-header";
import { Badge } from "@yekkola/ui/components/badge";
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
import { ArrowDown, ArrowUp } from "lucide-react";
import { useTranslations } from "next-intl";
import { type FormEvent, useState } from "react";

import { useErrorText } from "@/features/common/errors";
import { selectClass } from "@/features/common/styles";

type Category = AdminCategoryResource;

/**
 * Category tree (PRD-09 FR-09): top-level categories and their sub-categories (two levels), names in fr and en,
 * visibility, and the order of each level. A category in use can't be deleted — hide it instead.
 */
export function CategoryTree() {
  const t = useTranslations("admin.categories");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const categories = useAdminCategoriesIndex({ query: { select: (r) => success(r).data } });
  const all = categories.data ?? [];
  const top = all.filter((c) => c.parent_id === null);
  const childrenOf = (id: string) => all.filter((c) => c.parent_id === id);
  const refresh = () => queryClient.invalidateQueries({ queryKey: getAdminCategoriesIndexQueryKey() });

  const order = useMutation({
    mutationFn: ({ parentId, ids }: { parentId: string | null; ids: string[] }) =>
      adminCategoriesOrder({ parent_id: parentId, ids }),
    onSettled: refresh,
  });
  const remove = useMutation({ mutationFn: (id: string) => adminCategoriesDestroy(id), onSettled: refresh });

  const move = (level: Category[], index: number, by: -1 | 1, parentId: string | null) => {
    const ids = level.map((c) => c.id);
    const [moved] = ids.splice(index, 1);
    if (moved === undefined) return;
    ids.splice(index + by, 0, moved);
    order.mutate({ parentId, ids });
  };

  const row = (c: Category, level: Category[], index: number) => (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-background px-3 py-2">
      <span className="grid">
        <span className="font-semibold">
          {c.name.fr} <span className="font-normal text-muted-foreground">/ {c.name.en}</span>
        </span>
        <span className="text-xs text-muted-foreground">
          {c.slug} · {t("courseCount", { count: c.course_count })}
        </span>
      </span>
      <span className="flex flex-wrap items-center gap-1">
        {c.is_visible ? null : <Badge variant="secondary">{t("hidden")}</Badge>}
        <Button
          size="icon"
          variant="ghost"
          aria-label={t("moveUp", { name: c.name.fr ?? "" })}
          disabled={index === 0 || order.isPending}
          onClick={() => move(level, index, -1, c.parent_id)}
        >
          <ArrowUp aria-hidden />
        </Button>
        <Button
          size="icon"
          variant="ghost"
          aria-label={t("moveDown", { name: c.name.fr ?? "" })}
          disabled={index === level.length - 1 || order.isPending}
          onClick={() => move(level, index, 1, c.parent_id)}
        >
          <ArrowDown aria-hidden />
        </Button>
        <CategoryForm category={c} parents={top} onSaved={refresh} />
        {c.can_delete ? (
          <ConfirmDialog
            trigger={
              <Button size="sm" variant="ghost">
                {t("delete")}
              </Button>
            }
            title={t("deleteConfirm", { name: c.name.fr ?? "" })}
            confirmLabel={t("delete")}
            cancelLabel={common("cancel")}
            destructive
            onConfirm={async () => {
              await remove.mutateAsync(c.id);
            }}
            error={remove.isError && remove.variables === c.id ? errorText(remove.error) : undefined}
            onOpenChange={(open) => {
              if (open) remove.reset();
            }}
          />
        ) : null}
      </span>
    </div>
  );

  return (
    <div className="mx-auto grid max-w-4xl gap-6 px-4 py-8 sm:px-6">
      <PageHeader
        title={t("title")}
        description={t("intro")}
        actions={<CategoryForm parents={top} onSaved={refresh} />}
      />
      {categories.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(categories.error)}
        </p>
      ) : null}
      {order.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(order.error)}
        </p>
      ) : null}
      {top.length === 0 && categories.isSuccess ? (
        <p className="text-sm text-muted-foreground">{t("empty")}</p>
      ) : null}
      <ol className="grid gap-3">
        {top.map((c, i) => {
          const children = childrenOf(c.id);
          return (
            <li key={c.id} className="grid gap-2">
              {row(c, top, i)}
              {children.length > 0 ? (
                <ol className="ml-6 grid gap-2 border-l pl-4">
                  {children.map((child, j) => (
                    <li key={child.id}>{row(child, children, j)}</li>
                  ))}
                </ol>
              ) : null}
            </li>
          );
        })}
      </ol>
    </div>
  );
}

/** Create (no category) or edit a category. */
function CategoryForm({
  category,
  parents,
  onSaved,
}: {
  category?: Category;
  parents: Category[];
  onSaved: () => void;
}) {
  const t = useTranslations("admin.categories");
  const common = useTranslations("common");
  const errorText = useErrorText();
  const empty = {
    parent: "",
    slug: "",
    nameFr: "",
    nameEn: "",
    descFr: "",
    descEn: "",
    icon: "",
    visible: true,
  };
  const fromCategory = (c: Category) => ({
    parent: c.parent_id ?? "",
    slug: c.slug,
    nameFr: c.name.fr ?? "",
    nameEn: c.name.en ?? "",
    descFr: c.description.fr ?? "",
    descEn: c.description.en ?? "",
    icon: c.icon ?? "",
    visible: c.is_visible,
  });
  const [open, setOpen] = useState(false);
  const [v, setV] = useState(category ? fromCategory(category) : empty);
  const set = (patch: Partial<typeof v>) => setV({ ...v, ...patch });

  const save = useMutation({
    mutationFn: async () => {
      const body = {
        parent_id: v.parent || null,
        slug: v.slug.trim() || null,
        name: { fr: v.nameFr.trim(), en: v.nameEn.trim() },
        description: { fr: v.descFr.trim() || null, en: v.descEn.trim() || null },
        icon: v.icon.trim() || null,
        is_visible: v.visible,
      };
      if (category) await adminCategoriesUpdate(category.id, body);
      else await adminCategoriesStore(body);
    },
    onSuccess: () => {
      setOpen(false);
      onSaved();
    },
  });
  const errors = save.error instanceof ApiError ? save.error.fieldErrors() : {};
  const field = (id: string, label: string, key: keyof typeof v, error?: string) => (
    <div className="grid gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} value={String(v[key])} onChange={(e) => set({ [key]: e.target.value })} />
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  );
  // A category with sub-categories stays top-level; one can't sit under itself.
  const parentChoices = parents.filter((p) => p.id !== category?.id);
  const canHaveParent = !category || category.children_count === 0;

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        if (next) {
          setV(category ? fromCategory(category) : empty);
          save.reset();
        }
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm" variant={category ? "outline" : "default"}>
          {category ? t("edit") : t("create")}
        </Button>
      </DialogTrigger>
      <DialogContent closeLabel={common("close")}>
        <DialogHeader>
          <DialogTitle>
            {category ? t("editTitle", { name: category.name.fr ?? "" }) : t("create")}
          </DialogTitle>
          <DialogDescription>{t("formHint")}</DialogDescription>
        </DialogHeader>
        <form
          noValidate
          className="grid gap-4"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (!save.isPending) save.mutate();
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            {field("cat-name-fr", t("nameFr"), "nameFr", errors["name.fr"])}
            {field("cat-name-en", t("nameEn"), "nameEn", errors["name.en"])}
            {field("cat-desc-fr", t("descFr"), "descFr", errors["description.fr"])}
            {field("cat-desc-en", t("descEn"), "descEn", errors["description.en"])}
            {field("cat-slug", t("slug"), "slug", errors.slug)}
            {field("cat-icon", t("icon"), "icon", errors.icon)}
          </div>
          {canHaveParent ? (
            <div className="grid gap-2">
              <Label htmlFor="cat-parent">{t("parent")}</Label>
              <select
                id="cat-parent"
                className={selectClass}
                value={v.parent}
                onChange={(e) => set({ parent: e.target.value })}
              >
                <option value="">{t("topLevel")}</option>
                {parentChoices.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name.fr}
                  </option>
                ))}
              </select>
            </div>
          ) : null}
          <label className="flex items-center gap-2 text-sm font-semibold">
            <input
              type="checkbox"
              className="size-4 accent-primary"
              checked={v.visible}
              onChange={(e) => set({ visible: e.target.checked })}
            />
            {t("visible")}
          </label>
          {save.isError && Object.keys(errors).length === 0 ? (
            <p role="alert" className="text-sm text-destructive">
              {errorText(save.error)}
            </p>
          ) : null}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              {common("cancel")}
            </Button>
            <Button
              type="submit"
              disabled={save.isPending || !v.nameFr.trim() || !v.nameEn.trim()}
              aria-busy={save.isPending}
            >
              {t("save")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
