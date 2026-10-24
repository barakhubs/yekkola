"use client";

import { useMutation } from "@tanstack/react-query";
import { success } from "@yekkola/api-client";
import { lessonsPreviewPlayback } from "@yekkola/api-client/endpoints/playback";
import { Button } from "@yekkola/ui/components/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@yekkola/ui/components/dialog";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { Headphones, PlayCircle } from "lucide-react";
import { useTranslations } from "next-intl";
import dynamic from "next/dynamic";

/** The player (and Mux Player's code) loads only when someone opens a preview — public pages stay light. */
const ProtectedPlayer = dynamic(
  () => import("@/features/player/components/protected-player").then((m) => m.ProtectedPlayer),
  {
    ssr: false,
    loading: () => <Skeleton className="aspect-video w-full" />,
  },
);

interface PreviewButtonProps {
  lessonUid: string;
  title: string;
  kind: "video" | "audio";
  /** Compact link-style button inside the curriculum. */
  compact?: boolean;
}

/** Preview of a free lesson without signing in (PRD-04 FR-06). */
export function PreviewButton({ lessonUid, title, kind, compact = false }: PreviewButtonProps) {
  const t = useTranslations("web.course");
  const common = useTranslations("common");
  const catalog = useTranslations("web.catalog");
  const playback = useMutation({
    mutationFn: async () => success(await lessonsPreviewPlayback(lessonUid)).data,
  });
  const label = kind === "audio" ? t("listenPreview") : t("watchPreview");
  const Icon = kind === "audio" ? Headphones : PlayCircle;

  return (
    <Dialog onOpenChange={(open) => open && !playback.data && !playback.isPending && playback.mutate()}>
      <DialogTrigger asChild>
        {compact ? (
          <Button variant="link" size="sm" className="h-auto p-0">
            <Icon aria-hidden />
            {catalog("preview")}
            <span className="sr-only">: {title}</span>
          </Button>
        ) : (
          <Button variant="outline" size="lg" className="w-full">
            <Icon aria-hidden />
            {label}
          </Button>
        )}
      </DialogTrigger>
      <DialogContent closeLabel={common("close")}>
        <DialogHeader>
          <DialogTitle>{t("previewTitle", { title })}</DialogTitle>
        </DialogHeader>
        {playback.isError ? (
          <div className="grid gap-3 py-6 text-center">
            <p role="alert">{t("previewError")}</p>
            <div>
              <Button variant="outline" onClick={() => playback.mutate()}>
                {common("retry")}
              </Button>
            </div>
          </div>
        ) : playback.data ? (
          <ProtectedPlayer playback={playback.data} title={title} autoPlay />
        ) : (
          <Skeleton className="aspect-video w-full" />
        )}
      </DialogContent>
    </Dialog>
  );
}
