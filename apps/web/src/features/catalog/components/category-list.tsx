import type { CategoryResource } from "@yekkola/api-client/model";
import { cn } from "@yekkola/ui/lib/utils";

import { Link } from "@/i18n/navigation";

interface CategoryListProps {
  categories: CategoryResource[];
  activeSlug?: string;
  className?: string;
}

/** Category chips with course counts; empty categories are left out. */
export function CategoryList({ categories, activeSlug, className }: CategoryListProps) {
  const shown = categories.filter((c) => c.course_count > 0);
  if (shown.length === 0) {
    return null;
  }

  return (
    <ul className={cn("flex flex-wrap gap-2", className)}>
      {shown.map((category) => (
        <li key={category.id}>
          <Link
            href={`/categories/${category.slug}`}
            aria-current={category.slug === activeSlug ? "page" : undefined}
            className={cn(
              "inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-semibold transition-colors hover:bg-accent",
              category.slug === activeSlug && "border-primary bg-accent text-accent-foreground",
            )}
          >
            {category.name}
            <span className="text-xs text-muted-foreground tabular-nums">{category.course_count}</span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
