"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiError, success } from "@yekkola/api-client";
import {
  adminSettingsBrandingDestroy,
  adminSettingsBrandingUpdate,
  getBrandingShowQueryKey,
  useBrandingShow,
} from "@yekkola/api-client/endpoints/branding";
import { Button } from "@yekkola/ui/components/button";
import { Skeleton } from "@yekkola/ui/components/skeleton";
import { useTranslations } from "next-intl";
import { useId, useRef } from "react";

import { useErrorText } from "@/features/common/errors";

const ASSETS = ["logo", "footer_logo", "favicon", "share_image"] as const;
type Asset = (typeof ASSETS)[number];

/**
 * Brand images (PRD-09 FR-15): each is uploaded and saved on its own; the API checks type, size and dimensions,
 * audits the change and refreshes the public pages. Removing one brings back the built-in default.
 */
export function BrandingSection() {
  const t = useTranslations("admin.settings");
  const errorText = useErrorText();
  const branding = useBrandingShow({ query: { select: (r) => success(r).data } });

  return (
    <section
      aria-labelledby="settings-branding"
      className="grid gap-4 rounded-xl border bg-background p-4 sm:p-6"
    >
      <div className="grid gap-1">
        <h2 id="settings-branding" className="text-lg font-bold">
          {t("groups.branding")}
        </h2>
        <p className="text-sm text-muted-foreground">{t("branding.intro")}</p>
      </div>
      {branding.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorText(branding.error)}
        </p>
      ) : null}
      <div className="grid gap-4 sm:grid-cols-2">
        {ASSETS.map((asset) =>
          branding.isPending ? (
            <Skeleton key={asset} className="h-40 w-full" />
          ) : (
            <AssetField key={asset} asset={asset} url={branding.data?.[`${asset}_url`] ?? null} />
          ),
        )}
      </div>
    </section>
  );
}

function AssetField({ asset, url }: { asset: Asset; url: string | null }) {
  const t = useTranslations("admin.settings.branding");
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const inputId = useId();
  const input = useRef<HTMLInputElement>(null);
  const change = useMutation({
    mutationFn: async (file: File | null) =>
      success(
        await (file ? adminSettingsBrandingUpdate(asset, { file }) : adminSettingsBrandingDestroy(asset)),
      ).data,
    onSuccess: (data) =>
      queryClient.setQueryData(getBrandingShowQueryKey(), {
        data: { data },
        status: 200,
        headers: new Headers(),
      }),
    onSettled: () => {
      if (input.current) input.current.value = "";
    },
  });
  const fileError = change.error instanceof ApiError ? change.error.fieldErrors().file : undefined;

  return (
    <div className="grid content-start gap-2 rounded-lg border p-3">
      <label htmlFor={inputId} className="text-sm font-semibold">
        {t(`${asset}.label`)}
      </label>
      <div className="flex h-24 items-center justify-center rounded-md bg-muted p-3">
        {url ? (
          // eslint-disable-next-line @next/next/no-img-element -- any storage host; a preview, not page content
          <img src={url} alt={t(`${asset}.label`)} className="max-h-full max-w-full object-contain" />
        ) : (
          <span className="text-sm text-muted-foreground">{t("default")}</span>
        )}
      </div>
      <p className="text-xs text-muted-foreground">{t(`${asset}.hint`)}</p>
      <input
        ref={input}
        id={inputId}
        type="file"
        accept={asset === "favicon" ? "image/png" : "image/png,image/webp,image/jpeg"}
        className="text-sm file:mr-3 file:rounded-md file:border file:bg-background file:px-3 file:py-1.5 file:text-sm"
        disabled={change.isPending}
        onChange={(e) => {
          const file = e.target.files?.[0];
          if (file) change.mutate(file);
        }}
      />
      <div className="flex flex-wrap items-center gap-3">
        {url ? (
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={change.isPending}
            onClick={() => change.mutate(null)}
          >
            {t("reset")}
          </Button>
        ) : null}
        {change.isPending ? <span className="text-sm text-muted-foreground">{t("uploading")}</span> : null}
        {change.isSuccess ? <span className="text-sm text-muted-foreground">{t("saved")}</span> : null}
        {change.isError ? (
          <p role="alert" className="text-sm text-destructive">
            {fileError ?? errorText(change.error)}
          </p>
        ) : null}
      </div>
    </div>
  );
}
