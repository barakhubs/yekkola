"use client";

import type { DataTableSort } from "@yekkola/ui/blocks/data-table";
import { useTranslations } from "next-intl";

/** "-created_at" ⇄ { id, desc } for the API's `sort` parameter. */
export function toSort(param: string | null): DataTableSort | null {
  return param ? { id: param.replace(/^-/, ""), desc: param.startsWith("-") } : null;
}

export function fromSort(next: DataTableSort | null): string | null {
  return next ? `${next.desc ? "-" : ""}${next.id}` : null;
}

/** Pagination labels for `DataTable`. */
export function useTableLabels() {
  const table = useTranslations("admin.table");

  return {
    previous: table("previous"),
    next: table("next"),
    page: (current: number, total: number) => table("page", { page: current, total }),
    pagination: table("pagination"),
  };
}
