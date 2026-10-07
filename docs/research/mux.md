# Mux — research findings & cost estimate

> Source: Mux docs (mux.com/docs, Oct 2026) via Context7 and mux.com/docs/pricing/video. Prices change — re-check before budgeting.
> Feeds: TODO Phase 0.4, PRD-03, PRD-07, `project-context.md` → Video & audio.

## 1. Findings

| Question | Finding | Impact |
|---|---|---|
| Which quality level supports DRM? | DRM requires `video_quality: plus` (or premium). **Basic cannot use DRM.** | Every protected video pays plus encoding. Use `plus`, never `premium`. |
| DRM on audio-only assets? | **Not supported** — DRM is only for video assets; audio uses signed ("protected") playback. | Audio needs a different protection path — see §2. |
| Offline DRM downloads | Supported via persistent licenses (`offline: true`, `licenseExpiration`, optional `playDuration` in the DRM token). Android: `MuxOfflineCmafHlsMediaSource` + Media3 `DownloadHelper`; iOS: `MuxOfflineAccessManager`. | Confirms the plan for video. |
| Download quality tiers | Android `PlaybackResolution` includes `LD_540`, `HD_720`, `FHD_1080`, `QHD_1440`. iOS documented tiers start at `.upTo720p`. | Default downloads: 540p Android, 720p iOS. |
| Static renditions (MP4 / audio-only M4A) | Available (`720p`, `480p`, `audio-only`…); now a paid add-on. | Useful for audio offline (§2). |
| Delivery free tier | First 100,000 delivered minutes/month free, all qualities/resolutions. | Covers early months. |
| Cold storage | On by default; reduces storage cost for rarely watched assets. | Long-tail courses get cheaper automatically. |

## 2. Audio lessons — provisional approach

Mux can't DRM audio-only assets, so:

- **Streaming:** audio-only Mux asset with **signed playback** (short-lived token, issued only to enrolled users) — same access rules as video, no DRM.
- **Offline (mobile):** download the audio-only static rendition through a short-lived signed URL into **app-private storage**, encrypted at rest with a per-device key held in Android Keystore / iOS Keychain. The app enforces the same expiry as the offline license setting and deletes on entitlement sync.
- **Trade-off:** weaker than DRM (a rooted/jailbroken device could extract the file). Acceptable for v1 because audio is the low-data option and per-viewer stamping isn't possible in audio anyway.
- **Alternative to evaluate in the spike:** wrap audio as video (still cover image + audio track) so it gets real DRM. Costs video-rate encoding/delivery and needs server-side FFmpeg before upload (breaks "media never touches our servers"), so it's the fallback, not the default.

## 3. Prices used (first volume tier, plus quality)

| Item | Up to 720p | 1080p | Audio-only |
|---|---|---|---|
| Encoding (one-off, per minute) | $0.025 | $0.03125 | $0.0025 |
| Storage (per minute per month) | $0.0024 | $0.0030 | $0.00024 |
| Delivery (per minute) | $0.0008 | $0.0010 | $0.00008 |
| DRM | $100/month + $0.003 per license | | |

Free: first 100,000 delivery minutes/month.

## 4. Cost estimate (720p cap, plus quality)

Assumptions are illustrative; replace with real projections.

| | Launch | Year 1 | Scale |
|---|---|---|---|
| Courses × video hours | 100 × 3 h | 500 × 5 h | 3,000 × 5 h |
| Video minutes stored | 18,000 | 150,000 | 900,000 |
| Monthly active students | 1,000 | 10,000 | 100,000 |
| Minutes delivered / month (3–5 h each) | 180,000 | 3,000,000 | 30,000,000 |
| DRM licenses / month (~20–30 each) | 20,000 | 300,000 | 3,000,000 |
| **Encoding (one-off, cumulative)** | ~$450 | ~$3,750 | ~$22,500 |
| **Storage / month** | ~$43 | ~$360 | ~$2,160 |
| **Delivery / month** (after 100k free) | ~$64 | ~$2,320 | ~$21,000* |
| **DRM / month** | ~$160 | ~$1,000 | ~$9,100 |
| **Total / month (excl. encoding)** | **~$270** | **~$3,700** | **~$32,000** |

\* Volume tiers lower the effective rate at scale; the first-tier rate is used here, so this is an upper bound.

## 5. Cost levers

1. **Cap resolution at 720p** for lecture content (check that the asset-level max-resolution setting allows 720p; if the minimum cap is 1080p, encoding is $0.03125/min).
2. **Offline-first is cheaper:** a download is delivered once, then watched many times offline at no delivery cost.
3. **Fewer DRM licenses:** cache streaming licenses for the session; renew offline licenses only near expiry.
4. **Cold storage** (default) for the long tail.
5. **Audio lessons** cost ~10× less than video at every step.
6. Free-course limits (platform setting) bound the spend on content that earns nothing.

## 6. Still to verify in the spike

- Asset-level max resolution 720p supported?
- Real per-tier download sizes for a typical 20-minute lecture (feeds "show size before download").
- DRM persistent license behaviour on low-end Android (Widevine L3) devices.
- Exact iOS minimum tier for downloads.
