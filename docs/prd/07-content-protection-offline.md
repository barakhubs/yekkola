# PRD-07 — Content Protection & Offline

| | |
|---|---|
| Status | Draft |
| Phase | 1 (API playlists, keys, offline keys), 2 (web player), 3 (mobile downloads) |
| Related | PRD-01 (devices), PRD-06, architecture §1.3-D/E, §3.6, §6 |

## 1. Summary

Professors' income depends on content not being freely shared. Yekkola uses **encrypted HLS** (AES-128) for video and audio stored in Cloudflare R2, **two watermark layers**, **per-student stamped PDFs**, **device limits**, and **encrypted offline downloads** on mobile — the core feature for students on expensive, unreliable data.

> **Owner decision, 2026-11-09:** media moves to R2 and Mux is dropped. Widevine/FairPlay DRM is replaced by encrypted HLS. This is weaker than DRM (anyone holding a valid key can decrypt the segments); the owner accepted it. Access rules, watermarks and revocation behaviour are unchanged.

## 2. Goals & non-goals

**Goals**
- No plain media files are ever delivered to clients.
- Download-then-watch works reliably on low-end Android phones.
- Students always know download size before using data.

**Non-goals**
- Forensic (invisible, per-viewer) watermarking — out of scope.
- Hardware-backed DRM (Widevine/FairPlay) — dropped with Mux (2026-11-09).
- Preventing screenshots/screen recording entirely — deterred, not prevented (Android `FLAG_SECURE` is used where possible).

## 3. User stories

1. As a professor, I trust that my videos can't simply be downloaded and shared.
2. As a student, I download lessons on Wi-Fi or cheap data and watch them later offline.
3. As a student, I choose download quality and see the size before downloading.
4. As a student, I manage downloads (see storage used, delete lessons/courses).
5. As a student with a new phone, I remove my old device so I can register the new one.
6. As a student, my downloads keep working offline for the license period, and renew automatically when I'm online.

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | Video and audio are transcoded to HLS (video: 720p cap plus lower tiers, e.g. 480p, 360p; audio: audio-only HLS) with segments encrypted by AES-128, one key per asset. Segments live in the private R2 bucket; keys are stored encrypted at rest in the database, never next to the segments. No public media URLs (preview lessons use the same playlist and key endpoints without enrollment). | Must |
| FR-02 | Playback endpoint: checks enrollment/preview, account status, device/stream limits; returns the playlist URL + watermark text. The API serves the playlists: segment URIs are short-lived pre-signed R2 URLs, and the key URI points to a key endpoint that runs the same checks. | Must |
| FR-03 | Burned-in watermark (Yekkola logo + professor name), applied by FFmpeg during transcoding. | Must |
| FR-04 | Per-viewer overlay: name + masked phone (e.g. `Marie K. · +243 8•• ••• 123`), semi-transparent, moving position every 30–60 s; web and mobile players. | Must |
| FR-05 | Concurrent web streams limited (setting, default 1) via stream sessions + heartbeat; the newest stream wins, the older one shows "playing on another screen". | Must |
| FR-06 | Registered device limit (setting, default 2) for mobile; device management in account settings (PRD-01). | Must |
| FR-07 | Offline license endpoint (registered device only): returns the chosen rendition's playlist and the asset key, with an expiry = setting (default 14 days); records `OfflineLicense`. The app stores the key wrapped with a per-device key. | Must |
| FR-08 | Download quality options: our lowest rendition by default (e.g. 360p or 480p), higher optional (up to 720p); estimated sizes shown per lesson and per course. | Must |
| FR-09 | Download manager: queue, pause/resume, survives app backgrounding and network drops, Wi-Fi-only toggle, storage usage, delete per lesson/course. | Must |
| FR-10 | Entitlement sync on app start + periodically when online: revoked enrollments → downloads deleted; offline expiries near their end renewed. | Must |
| FR-11 | Expired license while offline → clear message "Connect to the internet to renew"; renewal is automatic on next connection. | Must |
| FR-12 | Documents: stamped per student (name + phone + date in footer/diagonal), generated on a queue, cached; delivered via short-lived signed URL; stored in app-private storage on mobile. | Must |
| FR-13 | Android: `FLAG_SECURE` on player screens; iOS: hide content on screen capture where the API allows. | Should |
| FR-14 | **Deprecated (2026-11-09):** ~~Mux Flutter plugin (in-house) wrapping Android `MuxDownloadManager` + iOS `MuxOfflineAccessManager`.~~ No native-SDK plugin is needed with R2; replaced by FR-16. | — |
| FR-15 | Takedown (PRD-09): stops new playlists and keys immediately; content removed from devices at next sync. | Must |
| FR-16 | Mobile media: a standard Flutter video player that supports AES-128 HLS, with our own downloader for the encrypted segments (download, progress, pause/resume, delete, list) and our own key handling; play with the overlay. Plain media is never written to disk. **Prototype on low-end Android before the rest of mobile.** | Must |

## 5. Business rules

- **BR-01** Protection applies equally to free and paid courses.
- **BR-02** Offline downloads cannot be revoked remotely; they are deleted at the next sync, and worst-case exposure = the offline license duration. Keep the setting short (7–14 days).
- **BR-03** Lowering the device limit does not remove existing devices; it blocks new registrations until the user is under the limit.
- **BR-04** Removing a device revokes its token and marks its offline licenses revoked (deleted at that device's next sync if it comes online); it gets no new playlists or keys.
- **BR-05** Watermark text never includes the full phone number.

## 6. UX notes

- Download button shows size; long-press/menu offers quality.
- "Downloads" tab: per course, storage used, license expiry ("Available offline until 21 Oct").
- Clear explanation when the device limit is reached, with a one-tap path to manage devices.

## 7. Edge cases

- Phone storage full mid-download → pause with message; resume after space is freed.
- App reinstalled → new install ID → counts as a new device unless the old one is removed (show guidance).
- Student refunded while offline → content plays until license expiry or next sync, whichever comes first.
- Video and audio offline follow the same model: encrypted segments on disk, key wrapped with a per-device key, same expiry.

## 8. Acceptance criteria

- [ ] No API response ever contains an unsigned media URL for non-preview content.
- [ ] The key endpoint refuses a user without an active enrollment (non-preview), a suspended account, a replaced web stream, or an unregistered device.
- [ ] Playing on a second browser stops the first (with message) when the limit is 1.
- [ ] A downloaded lesson plays in airplane mode until license expiry and renews when online.
- [ ] Revoked enrollment → downloads deleted on next sync.
- [ ] Stamped PDF contains the student's name and masked phone.

## 9. Analytics events

`playback_token_issued {client}`, `stream_limit_hit`, `download_started {quality}`, `download_completed {bytes}`, `download_failed {reason}`, `offline_play`, `license_renewed`, `license_expired_offline`, `device_limit_reached`.

## 10. Open questions / to verify

- ~~Mux DRM for audio-only assets~~ — moot: Mux dropped (2026-11-09).
- ~~Exact Mux resolution tiers for downloads~~ — moot: the rendition ladder is ours.
- ~~Mux DRM license pricing~~ — moot; R2 + FFmpeg cost estimate pending (TODO 0.4).
- AES-128 HLS playback and offline download on low-end Android (2 GB RAM, Android 8+) and iOS — verify in the [R2 media spike](../spikes/r2-hls-media.md).
