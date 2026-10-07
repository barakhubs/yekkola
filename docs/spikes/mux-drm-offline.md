# Spike — Mux DRM, watermark, and offline downloads

**Goal:** prove the full content-protection chain before building on it (PRD-07). Background: [`docs/research/mux.md`](../research/mux.md).

**Needs:** Mux account, DRM enabled (DRM configuration ID), signing key, webhook secret; one Android test device (low-end, Widevine L3 if possible) and one iPhone.

## Steps

1. **Upload:** create a direct upload with `video_quality: plus`, DRM playback policy, burned-in watermark overlay (logo PNG), and the lowest allowed max resolution. Upload a 20-min lecture from a browser. Record webhook events.
2. **Web playback:** sign playback + DRM tokens server-side (`muxinc/mux-php`), play in Mux Player (React) in Chrome, Firefox, Safari, and Chrome on Android. Add a moving per-viewer text overlay.
3. **Android offline:** minimal Kotlin app using `MuxOfflineCmafHlsMediaSource` + Media3 `DownloadHelper`, DRM token with `offline: true`, `licenseExpiration: 600` (10 min for the test). Download at `LD_540`, go to airplane mode, play; wait past expiry, confirm playback stops; reconnect, renew.
4. **iOS offline:** same with `MuxOfflineAccessManager` at `.upTo720p`.
5. **Audio:** upload an audio-only file; verify signed playback works and DRM is rejected; download the audio-only static rendition via signed URL; encrypt at rest in app storage (§2 of the research doc).
6. **Flutter bridge feasibility:** call the Android download + play code from a Flutter `MethodChannel` (download, progress events, play in a platform view with overlay).
7. **Sizes:** record actual download size per tier for the 20-min lecture.

## Pass criteria

- [ ] DRM playback works on all target browsers and Android Chrome
- [ ] Offline playback works in airplane mode on Android and iOS
- [ ] Playback stops after license expiry and resumes after online renewal
- [ ] Burned-in watermark visible; per-viewer overlay renders above the video
- [ ] Flutter platform channel can drive download + playback
- [ ] Download sizes recorded per tier
- [ ] Audio signed playback + encrypted offline file works

## Results

_Not run yet._
