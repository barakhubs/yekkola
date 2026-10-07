# PRD-07 — Content Protection & Offline

| | |
|---|---|
| Status | Draft |
| Phase | 1 (API tokens/licenses), 2 (web player), 3 (mobile downloads + plugin) |
| Related | PRD-01 (devices), PRD-06, architecture §1.3-D/E, §3.6, §6 |

## 1. Summary

Professors' income depends on content not being freely shared. Yekkola uses **Mux DRM** for video and audio, **two watermark layers**, **per-student stamped PDFs**, **device limits**, and **DRM-protected offline downloads** on mobile — the core feature for students on expensive, unreliable data.

## 2. Goals & non-goals

**Goals**
- No plain media files are ever delivered to clients.
- Download-then-watch works reliably on low-end Android phones.
- Students always know download size before using data.

**Non-goals**
- Forensic (invisible, per-viewer) watermarking — not offered by Mux; out of scope.
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
| FR-01 | All video/audio assets created with DRM playback policy; no public playback IDs (except preview lessons, which use signed tokens without enrollment). | Must |
| FR-02 | Playback token endpoint: checks enrollment/preview, account status, device/stream limits; returns signed playback token + DRM license token (short TTL, e.g. 6 h) + watermark text. | Must |
| FR-03 | Burned-in watermark at upload (Yekkola logo + professor name), via Mux overlay. | Must |
| FR-04 | Per-viewer overlay: name + masked phone (e.g. `Marie K. · +243 8•• ••• 123`), semi-transparent, moving position every 30–60 s; web and mobile players. | Must |
| FR-05 | Concurrent web streams limited (setting, default 1) via stream sessions + heartbeat; the newest stream wins, the older one shows "playing on another screen". | Must |
| FR-06 | Registered device limit (setting, default 2) for mobile; device management in account settings (PRD-01). | Must |
| FR-07 | Offline license endpoint: signs DRM token with `offline: true`, `licenseExpiration` = setting (default 14 days); records `OfflineLicense`. | Must |
| FR-08 | Download quality options: lowest tier by default (540p Android / 720p tier iOS), higher optional; estimated sizes shown per lesson and per course. | Must |
| FR-09 | Download manager: queue, pause/resume, survives app backgrounding and network drops, Wi-Fi-only toggle, storage usage, delete per lesson/course. | Must |
| FR-10 | Entitlement sync on app start + periodically when online: revoked enrollments → downloads deleted; licenses near expiry renewed. | Must |
| FR-11 | Expired license while offline → clear message "Connect to the internet to renew"; renewal is automatic on next connection. | Must |
| FR-12 | Documents: stamped per student (name + phone + date in footer/diagonal), generated on a queue, cached; delivered via short-lived signed URL; stored in app-private storage on mobile. | Must |
| FR-13 | Android: `FLAG_SECURE` on player screens; iOS: hide content on screen capture where the API allows. | Should |
| FR-14 | Mux Flutter plugin (in-house): wraps Android `MuxDownloadManager` + iOS `MuxOfflineAccessManager`; API: download, progress stream, pause/resume, delete, list, play with overlay. **Prototype before the rest of mobile.** | Must |
| FR-15 | Takedown (PRD-09): stops new tokens immediately; content removed from devices at next sync. | Must |

## 5. Business rules

- **BR-01** Protection applies equally to free and paid courses.
- **BR-02** Offline licenses cannot be revoked remotely; worst-case exposure = license duration. Keep the setting short (7–14 days).
- **BR-03** Lowering the device limit does not remove existing devices; it blocks new registrations until the user is under the limit.
- **BR-04** Removing a device revokes its token and marks its offline licenses revoked (deleted at that device's next sync if it comes online).
- **BR-05** Watermark text never includes the full phone number.

## 6. UX notes

- Download button shows size; long-press/menu offers quality.
- "Downloads" tab: per course, storage used, license expiry ("Available offline until 21 Oct").
- Clear explanation when the device limit is reached, with a one-tap path to manage devices.

## 7. Edge cases

- Phone storage full mid-download → pause with message; resume after space is freed.
- App reinstalled → new install ID → counts as a new device unless the old one is removed (show guidance).
- Student refunded while offline → content plays until license expiry or next sync, whichever comes first.
- Mux SDK cannot download audio-only assets offline → fall back: **Open** (see §10).

## 8. Acceptance criteria

- [ ] No API response ever contains an unsigned media URL for non-preview content.
- [ ] Playing on a second browser stops the first (with message) when the limit is 1.
- [ ] A downloaded lesson plays in airplane mode until license expiry and renews when online.
- [ ] Revoked enrollment → downloads deleted on next sync.
- [ ] Stamped PDF contains the student's name and masked phone.

## 9. Analytics events

`playback_token_issued {client}`, `stream_limit_hit`, `download_started {quality}`, `download_completed {bytes}`, `download_failed {reason}`, `offline_play`, `license_renewed`, `license_expired_offline`, `device_limit_reached`.

## 10. Open questions / to verify

- Mux DRM + offline download support for **audio-only** assets (verify early; fallback: encrypted audio via a different mechanism).
- Exact Mux resolution tiers available for downloads on each platform.
- Mux DRM license pricing at expected volume.
