---
paths:
  - "api/app/Domain/Protection/**"
  - "api/app/Integrations/Video/**"
  - "apps/web/src/features/player/**"
---

# Content protection rules

- Never return an unsigned or public media URL for non-preview content. Video/audio are AES-128 encrypted HLS in the private R2 bucket: the API serves the playlists (segments as short-lived pre-signed URLs) and the key, re-checking access (`AuthorizeMedia`) on every playlist and key request.
- The AES key lives only in `media_assets.encryption_key` (encrypted cast); never write it to R2, logs, or an API response other than the key endpoint (and the offline license).
- Offline downloads keep the encrypted segments and a key wrapped with a per-device key; never write plain media files or a plain key to device storage.
- Offline license expiry comes from the platform setting, never a constant.
- Per-viewer watermark text shows name + **masked** phone — never the full number.
- Documents are served as per-student stamped PDFs through short-lived signed URLs.
- Remember: an issued offline license can't be revoked remotely — revocation relies on entitlement sync and license expiry.
- See `docs/prd/07-content-protection-offline.md`.
