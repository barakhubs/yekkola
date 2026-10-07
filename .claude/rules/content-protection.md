---
paths:
  - "api/app/Domain/Protection/**"
  - "api/app/Integrations/Video/**"
  - "mobile/packages/mux_player_plugin/**"
  - "apps/web/src/features/player/**"
---

# Content protection rules

- Never return an unsigned or public media URL for non-preview content. Video/audio go through Mux signed playback + DRM tokens issued only after an active-enrollment check.
- Offline downloads only via Mux persistent DRM licenses; never write plain media files to device storage.
- Offline license expiry comes from the platform setting, never a constant.
- Per-viewer watermark text shows name + **masked** phone — never the full number.
- Documents are served as per-student stamped PDFs through short-lived signed URLs.
- Remember: an issued offline license can't be revoked remotely — revocation relies on entitlement sync and license expiry.
- See `docs/prd/07-content-protection-offline.md`.
