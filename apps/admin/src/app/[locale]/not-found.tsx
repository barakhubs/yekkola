import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { useTranslations } from "next-intl";

export default function NotFound() {
  const t = useTranslations("errors");

  return (
    <main className="mx-auto max-w-xl px-6 py-24">
      <EmptyState title={t("notFound")} />
    </main>
  );
}
