"use client";

import {
  type ColumnDef,
  createColumnHelper,
  FlexRender,
  type RowData,
  rowSortingFeature,
  type SortingState,
  tableFeatures,
  useTable,
} from "@tanstack/react-table";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import type * as React from "react";

import { Button } from "../components/button";
import { Skeleton } from "../components/skeleton";
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "../components/table";

/** Features every data table has: sorting is done by the server (`manualSorting`), paging by the caller. */
export const dataTableFeatures = tableFeatures({ rowSortingFeature });

/** A column of any value type, as `dataTableColumns<T>().columns([...])` returns them. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- each accessor column has its own value type
export type DataTableColumn<TData extends RowData> = ColumnDef<typeof dataTableFeatures, TData, any>;

/** Typed column helper for a row type: `const col = dataTableColumns<Row>(); col.columns([col.accessor("title", …)])`. */
export function dataTableColumns<TData extends RowData>() {
  return createColumnHelper<typeof dataTableFeatures, TData>();
}

export interface DataTableSort {
  id: string;
  desc: boolean;
}

interface DataTableProps<TData extends RowData> {
  columns: Array<DataTableColumn<TData>>;
  data: TData[];
  getRowId: (row: TData) => string;
  /** Describes the table for screen readers (visually hidden). */
  caption: string;
  /** Server-side sort: the current one (null = the API's default order) and what to do when a header is clicked. */
  sort?: DataTableSort | null;
  onSortChange?: (sort: DataTableSort | null) => void;
  /** Server-side pages, 1-based. */
  page: number;
  pageCount: number;
  onPageChange: (page: number) => void;
  loading?: boolean;
  empty: React.ReactNode;
  labels: {
    previous: string;
    next: string;
    page: (page: number, total: number) => string;
    pagination: string;
  };
}

/**
 * Server-driven list (architecture §5.3): the API pages and sorts; the caller keeps page and sort in the URL.
 * Sortable headers are buttons with `aria-sort` on the column; every label comes from the app.
 */
function DataTable<TData extends RowData>({
  columns,
  data,
  getRowId,
  caption,
  sort = null,
  onSortChange,
  page,
  pageCount,
  onPageChange,
  loading = false,
  empty,
  labels,
}: DataTableProps<TData>) {
  "use no memo"; // TanStack Table mutates its instance; keep it away from the React Compiler.

  const sorting: SortingState = sort ? [sort] : [];
  const table = useTable({
    features: dataTableFeatures,
    columns,
    data,
    getRowId,
    manualSorting: true,
    enableSortingRemoval: true,
    state: { sorting },
    onSortingChange: (updater) => {
      const next = typeof updater === "function" ? updater(sorting) : updater;
      onSortChange?.(next[0] ? { id: next[0].id, desc: next[0].desc } : null);
    },
  });

  const columnCount = table.getAllLeafColumns().length;

  return (
    <div className="grid min-w-0 gap-3">
      <div className="min-w-0 rounded-xl border bg-background">
        <Table aria-busy={loading || undefined}>
          <TableCaption className="sr-only">{caption}</TableCaption>
          <TableHeader>
            {table.getHeaderGroups().map((group) => (
              <TableRow key={group.id} className="hover:bg-transparent">
                {group.headers.map((header) => {
                  const sorted = header.column.getIsSorted();
                  const canSort = Boolean(onSortChange) && header.column.getCanSort();
                  return (
                    <TableHead
                      key={header.id}
                      aria-sort={
                        sorted === "asc" ? "ascending" : sorted === "desc" ? "descending" : undefined
                      }
                    >
                      {header.isPlaceholder ? null : canSort ? (
                        <button
                          type="button"
                          className="-mx-2 inline-flex items-center gap-1 rounded px-2 py-1 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                          onClick={header.column.getToggleSortingHandler()}
                        >
                          <FlexRender header={header} />
                          {sorted === "asc" ? (
                            <ArrowUp aria-hidden className="size-3.5" />
                          ) : sorted === "desc" ? (
                            <ArrowDown aria-hidden className="size-3.5" />
                          ) : (
                            <ArrowUpDown aria-hidden className="size-3.5 opacity-50" />
                          )}
                        </button>
                      ) : (
                        <FlexRender header={header} />
                      )}
                    </TableHead>
                  );
                })}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {loading && data.length === 0 ? (
              Array.from({ length: 5 }, (_, i) => (
                <TableRow key={`loading-${i}`}>
                  <TableCell colSpan={columnCount}>
                    <Skeleton className="h-5 w-full" />
                  </TableCell>
                </TableRow>
              ))
            ) : data.length === 0 ? (
              <TableRow className="hover:bg-transparent">
                <TableCell colSpan={columnCount} className="py-10 text-center text-muted-foreground">
                  {empty}
                </TableCell>
              </TableRow>
            ) : (
              table.getRowModel().rows.map((row) => (
                <TableRow key={row.id}>
                  {row.getAllCells().map((cell) => (
                    <TableCell key={cell.id}>
                      <FlexRender cell={cell} />
                    </TableCell>
                  ))}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
      {pageCount > 1 ? (
        <nav aria-label={labels.pagination} className="flex items-center justify-between gap-4">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
            {labels.previous}
          </Button>
          <span className="text-sm text-muted-foreground">{labels.page(page, pageCount)}</span>
          <Button
            variant="outline"
            size="sm"
            disabled={page >= pageCount}
            onClick={() => onPageChange(page + 1)}
          >
            {labels.next}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}

export { DataTable };
export type { DataTableProps };
