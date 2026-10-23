import type * as React from "react";

import { cn } from "../lib/utils";

interface PageHeaderProps extends Omit<React.ComponentProps<"header">, "title"> {
  title: React.ReactNode;
  description?: React.ReactNode;
  /** Primary actions, right-aligned on wide screens. */
  actions?: React.ReactNode;
}

/** Page title, optional description, and actions. */
function PageHeader({ title, description, actions, className, ...props }: PageHeaderProps) {
  return (
    <header
      className={cn("flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between", className)}
      {...props}
    >
      <div className="grid gap-1">
        <h1 className="text-2xl leading-tight font-bold sm:text-3xl">{title}</h1>
        {description ? <p className="text-muted-foreground">{description}</p> : null}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
    </header>
  );
}

export { PageHeader };
export type { PageHeaderProps };
