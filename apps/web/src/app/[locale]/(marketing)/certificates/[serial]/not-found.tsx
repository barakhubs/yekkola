import { EmptyState } from "@yekkola/ui/blocks/empty-state";
import { ShieldX } from "lucide-react";
import { useTranslations } from "next-intl";

/** Unknown or malformed serial: a real 404 with a hint about the format. */
export default function CertificateNotFound() {
  const t = useTranslations("web.certificate");

  return (
    <div className="mx-auto max-w-xl px-4 py-16">
      <EmptyState icon={<ShieldX />} title={t("notFound")} description={t("notFoundHint")} />
    </div>
  );
}
