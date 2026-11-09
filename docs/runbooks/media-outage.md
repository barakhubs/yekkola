# Runbook — R2 or transcoding outage

Media lives in Cloudflare R2 and is transcoded by FFmpeg on the `media` queue (owner decision, 2026-11-09; replaces the Mux outage runbook). Until the R2 driver is live (TODO 1.5b), production still runs the Mux driver: check status.mux.com for playback and upload failures.

**Symptoms:** playback errors spike (Sentry `playback_error`), segment requests to R2 fail (403/5xx), uploads fail or stay in `waiting_upload`, media assets stuck in `processing`, `media` queue wait time growing.

**Impact:**

- **R2 down:** streaming, new downloads and uploads fail. **Already-downloaded lessons keep playing offline** (encrypted segments + stored key, until their expiry) — say so in comms.
- **Transcoding down:** new uploads stay in `processing`; existing lessons keep playing.
- **Our playlist or key endpoints failing:** streaming and new downloads fail even though R2 is up.

## Check

1. Cloudflare status page (R2) and the R2 dashboard: error rate, request volume.
2. Sentry: errors by client; are playlist and key requests (our API) healthy?
3. Horizon: `media` queue depth and wait time, failed `TranscodeMedia` jobs and their errors (FFmpeg exit code, out of memory, timeout).
4. Count of media assets in `processing` for more than 1 h, and in `waiting_upload` for more than a day.

## Act

- **R2 down:** post an in-app banner (FR/EN): streaming temporarily unavailable, downloaded lessons still work. No code change needed.
- **Segment URLs refused (R2 up):** check the R2 access keys and bucket env vars, clock skew on API instances (pre-signed URLs), and recent deploys; roll back if needed.
- **Browser uploads failing (R2 up):** check the bucket CORS rules against the web origins.
- **Key endpoint failing:** check the app key (keys are encrypted at rest with it) and recent deploys. Never serve keys some other way.
- **Transcoding failing or slow:** check worker CPU/memory and FFmpeg availability on the workers; scale the `media` workers; retry failed jobs once the cause is fixed. Assets marked `errored` can be re-uploaded by the professor.

## Recover

- Remove the banner; confirm the playback error rate is back to baseline; no assets left in `processing` for more than 1 h.

## Follow up

- Incident note; consider status-page subscription alerts and an alert on `media` queue wait time.
- Keys or access keys exposed? Rotate the R2 access keys. A leaked AES key exposes only its asset: re-transcode that asset with a new key.
