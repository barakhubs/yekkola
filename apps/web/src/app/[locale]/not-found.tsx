import { Button } from "@yekkola/ui/components/button";
import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { useTranslations } from "next-intl";

import { Link } from "@/i18n/navigation";

export default function NotFound() {
  const t = useTranslations();

  return (
    <main className="mx-auto max-w-xl px-4 py-24">
      <EmptyState
        title={t("errors.notFound")}
        action={
          <Button asChild>
            <Link href="/">{t("common.appName")}</Link>
          </Button>
        }
      />
    </main>
  );
}
