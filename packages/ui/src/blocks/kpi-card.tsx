import type * as React from "react";

import { Card, CardContent, CardHeader, CardTitle } from "../components/card";
import { Skeleton } from "../components/skeleton";
import { cn } from "../lib/utils";

interface KpiCardProps extends Omit<React.ComponentProps<"div">, "title"> {
  title: React.ReactNode;
  /** Already formatted (money, count, percentage) by the caller. */
  value: React.ReactNode;
  /** e.g. "+12 % vs last month" — formatted and translated by the caller. */
  hint?: React.ReactNode;
  icon?: React.ReactNode;
  loading?: boolean;
}

/** One headline number on a dashboard. Values come pre-aggregated from the API. */
function KpiCard({ title, value, hint, icon, loading = false, className, ...props }: KpiCardProps) {
  return (
    <Card className={cn("gap-3", className)} {...props}>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-sm font-semibold text-muted-foreground">{title}</CardTitle>
        {icon ? <span className="text-muted-foreground [&_svg]:size-4">{icon}</span> : null}
      </CardHeader>
      <CardContent className="grid gap-1">
        {loading ? (
          <Skeleton className="h-8 w-24" />
        ) : (
          <span className="text-2xl font-extrabold tabular-nums">{value}</span>
        )}
        {hint && !loading ? <span className="text-xs text-muted-foreground">{hint}</span> : null}
      </CardContent>
    </Card>
  );
}

export { KpiCard };
export type { KpiCardProps };
