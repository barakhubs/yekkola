import type { CategoryResource } from "@yekkola/api-client/model";

/** A category and its parent (if it is a subcategory), found by slug in the visible tree. */
export function findCategory(
  tree: CategoryResource[],
  slug: string,
): { category: CategoryResource; parent: CategoryResource | null } | null {
  for (const top of tree) {
    if (top.slug === slug) {
      return { category: top, parent: null };
    }
    const child = top.children.find((c) => c.slug === slug);
    if (child) {
      return { category: child, parent: top };
    }
  }

  return null;
}
