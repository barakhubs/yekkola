# Spike — FFmpeg on Laravel Cloud + AES-128 HLS on low-end Android

**Goal:** prove the R2 media chain before building the R2 driver (owner decision, 2026-11-09; replaces the [Mux DRM spike](mux-drm-offline.md)). PRD-03 FR-04/05, PRD-07.

**Needs:** Laravel Cloud staging with a queue worker; Cloudflare account with a private R2 bucket and access keys; one low-end Android phone (2 GB RAM, Android 8+) and one iPhone.

## Steps

1. **FFmpeg on Cloud:** on a Laravel Cloud queue worker, check that `ffmpeg` is available (or can be installed in the build) and record CPU, memory, and job time limits.
2. **Transcode:** from a 20-min lecture uploaded to R2 with a pre-signed URL (from a browser, CORS set), run one job: HLS 720p + 480p + 360p, AES-128 (one key), burned-in logo + professor name, poster frame. Also an audio-only HLS from a 60-min audio file. Record wall time, peak memory, and output sizes per rendition.
3. **Queue sizing:** from step 2, estimate transcoding time per media hour and how many `media` workers a busy upload day needs. If Cloud workers can't run FFmpeg or are too small, try a dedicated transcoding worker or container and note the cost.
4. **Web playback:** API-served playlist with pre-signed segment URLs and a key endpoint; play with hls.js in Chrome, Firefox, Safari, and Chrome on the low-end Android phone. Add the moving per-viewer overlay.
5. **Android offline:** minimal Flutter app with a standard video player that supports AES-128 HLS; download the 360p rendition's segments with our own downloader, store the key wrapped with a Keystore key, play in airplane mode; check expiry handling and memory use.
6. **iOS offline:** same on the iPhone (AVPlayer with local AES-128 HLS; key wrapped with a Keychain key).
7. **Sizes:** record the actual download size per rendition, to set `DOWNLOAD_ESTIMATE_*_KBPS`.

## Pass criteria

- [ ] FFmpeg runs on Cloud workers (or a dedicated worker is costed)
- [ ] Transcoding time per media hour and queue sizing recorded
- [ ] Encrypted HLS plays on all target browsers and Chrome on the low-end Android phone
- [ ] Offline playback in airplane mode on Android and iOS, smooth on the 2 GB phone
- [ ] No plain media file and no plain key on device storage
- [ ] Burned-in watermark visible; per-viewer overlay renders above the video
- [ ] Download sizes recorded per rendition

## Results

_Not run yet._
