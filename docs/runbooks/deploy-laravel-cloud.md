# Deploying the Next.js apps on Laravel Cloud

The web app and the back office deploy as two separate Cloud applications from this one repository (Cloud monorepo
support). Each is scoped to its own folder.

## Why it is set up this way

- **Only the app's folder is deployed**, but the whole repository is available during the build. We use pnpm
  workspaces, so `apps/<app>/node_modules` is mostly links into the root `node_modules`, which would not be deployed.
  Both apps therefore build with `output: "standalone"` (`next.config.ts`): `next build` writes a self-contained
  server, with its dependencies copied in, under `apps/<app>/.next/standalone/`. `scripts/standalone-assets.mjs`
  (run by each app's `build` script) copies `.next/static` (and `public/`, if any) next to it.
- **Cloud looks for a lockfile in the app's folder** before it builds. The only real lockfile is `pnpm-lock.yaml`
  at the repo root, so `apps/admin/pnpm-lock.yaml` and `apps/web/pnpm-lock.yaml` are git symbolic links to it
  (`../../pnpm-lock.yaml`). On Windows checkouts without symlink support they appear as small text files containing
  that path — leave them as they are.
- **Start:** Cloud runs the `start` script, which is `node start.mjs`: it starts the standalone server on `PORT`
  (set by Cloud) and listens on every interface, **IPv4 and IPv6** (`HOSTNAME=::`). Cloud's startup probes come
  over IPv6: with `0.0.0.0` the server starts, the probe never reaches it, and Cloud aborts the deployment
  ("App cluster shutdown drain… exitCode 143").
- **Health check:** both apps also serve `GET /up` → 200 `ok` (`src/app/up/route.ts`), outside the locale
  middleware, for a custom HTTP health check if one is set.

## Settings per application

| Setting | `yekkola-admin` | `yekkola-web` |
| --- | --- | --- |
| Root directory | `apps/admin` | `apps/web` |
| Runtime | Node.js 22 or 24 | Node.js 22 or 24 |
| Build command | see below | see below |
| Start command | `node start.mjs` | `node start.mjs` |

Build command (replace `admin` with `web` for the web app):

```sh
cd ../.. && npm install -g pnpm@10.11.0 && pnpm install --frozen-lockfile && pnpm --filter @yekkola/admin build
```

The pnpm version matches `packageManager` in the root `package.json`; update both together.

## Environment variables

Set them on each Cloud environment from the app's `.env.example`. `NEXT_PUBLIC_*` values are compiled into the
browser code at build time: **redeploy after changing one**.

- Both: `NEXT_PUBLIC_API_URL` (the API's origin, on the same parent domain), `NEXT_PUBLIC_IMAGE_HOSTS`,
  `NEXT_PUBLIC_TURNSTILE_SITE_KEY`.
- Web only: `NEXT_PUBLIC_SITE_URL` (Cloud sets it to the app's URL), `WEB_SERVER_KEY`, `WEB_REVALIDATE_SECRET`
  (same values as in the API), optional `API_INTERNAL_URL`.
- On the API: `FRONTEND_WEB_URL` and `FRONTEND_ADMIN_URL` must be these apps' URLs (CORS, "View as" redirects).

## Files and media on Cloudflare R2

Laravel Cloud's disk is not shared between instances and is wiped on deploy, so **nothing may be stored on the
API's local disk**: with `PUBLIC_DISK=public`, uploaded logos, avatars and covers get `https://api.…/storage/…`
URLs that answer 404. Use R2 for every environment except your own machine.

1. **Buckets** (Cloudflare dashboard → R2 → Create bucket), one pair per environment, e.g.
   `yekkola-staging-private` and `yekkola-staging-public` (and `yekkola-production-…`). Location: automatic.
2. **Public URL for the public bucket** (bucket → Settings → Public access): connect a custom domain such as
   `media.yekkola.com` (recommended; the r2.dev address is rate-limited and for testing only). This is
   `R2_PUBLIC_URL`, e.g. `https://media.yekkola.com`. The private bucket stays private (no public access).
3. **API token** (R2 → Manage R2 API tokens → Create API token): permission *Object Read & Write*, limited to the
   environment's two buckets. Copy the *Access Key ID*, the *Secret Access Key* (shown once) and the S3 endpoint
   `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`.
4. **CORS on the private bucket** (bucket → Settings → CORS policy), with your web and admin origins:

   ```json
   [
     {
       "AllowedOrigins": ["https://www.yekkola.com", "https://admin.yekkola.com"],
       "AllowedMethods": ["GET", "HEAD", "PUT"],
       "AllowedHeaders": ["*"],
       "ExposeHeaders": ["ETag"],
       "MaxAgeSeconds": 3600
     }
   ]
   ```

   `PUT` + `ETag`: the studio uploads video in parts straight to R2. `GET`/`HEAD`: the player loads the video
   segments. No CORS rule is needed on the public bucket (images load with plain `<img>` tags).
5. **API environment variables** (Laravel Cloud → `yekkola-api` → the environment → Settings → Environment
   variables), then **redeploy**:

   ```dotenv
   R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
   R2_ACCESS_KEY_ID=<access key id>
   R2_SECRET_ACCESS_KEY=<secret access key>
   R2_PRIVATE_BUCKET=yekkola-staging-private
   R2_PUBLIC_BUCKET=yekkola-staging-public
   R2_PUBLIC_URL=https://media.yekkola.com
   PUBLIC_DISK=r2-public
   PRIVATE_DISK=r2-private
   MEDIA_DISK=r2-private
   ```

   Lesson video moves to R2 only with `VIDEO_DRIVER=r2`, and that needs FFmpeg on the queue workers
   (`FFMPEG_BINARY`, `FFPROBE_BINARY`; R2 media spike, TODO 0.4). Until then keep `VIDEO_DRIVER=fake`.
6. **Web and admin apps**: add the public host to `NEXT_PUBLIC_IMAGE_HOSTS` (e.g. `media.yekkola.com`, comma
   separated with any others) and **redeploy both** (the value is compiled in).
7. **Re-upload** what was stored on the old local disk: brand images (back office → Settings → Branding), then
   professor avatars and course covers. Files on the API's old disk are gone and are not moved automatically.
8. **Check**: a brand image URL now starts with `R2_PUBLIC_URL` and opens in the browser; uploading an identity
   document in the studio and opening it in the back office works (private bucket, streamed by the API).

## API workers and scheduler

- Queue workers must listen on `payments,otp,default,notifications` (same order as `config/horizon.php`), or
  notifications and other jobs never run. Transcoding runs on its own connection: with Horizon this is the
  `supervisor-media` supervisor; without Horizon run a second worker,
  `php artisan queue:work redis-media --queue=media --timeout=12600` (`MEDIA_TRANSCODE_TIMEOUT` + 30 min).
- The scheduler must run (`php artisan schedule:run` every minute): payment reconciliation, payouts, the ledger
  check, the professor digest and staff alerts depend on it.
- Push notifications: `PUSH_DRIVER=fcm` with `FCM_PROJECT_ID`, `FCM_CLIENT_EMAIL`, `FCM_PRIVATE_KEY` (from a Firebase
  service account; write its line breaks as `\n`). Without them, leave `PUSH_DRIVER=log` (staging only).

## Troubleshooting (seen on the first deploys)

| Symptom | Cause | Fix |
| --- | --- | --- |
| Logos, avatars or covers return 404 at `https://api.…/storage/…` | Files on the API's local disk (`PUBLIC_DISK=public`): not served, not shared, wiped on deploy | Move to R2 (section "Files and media on Cloudflare R2"), redeploy, re-upload the images. |
| Build: `corepack: not found` | Old build command | Use the build command above (pnpm via `npm install -g`). |
| Build: page "took more than 60 seconds" | The API wasn't answering while pages were prerendered | Fixed in code: build-time API calls time out after 10 s and the page fills in at its first revalidation. Deploy the API first anyway. |
| Deploy aborts after "Ready", exit code 143 | Server not reachable by Cloud's probes | Fixed in code: `start.mjs` listens on IPv4 and IPv6. Check the start command is `node start.mjs`. |
| Pages that load data return 500, log shows `Failed to parse URL from /api/v1/…` | An API URL variable set but empty (`API_INTERNAL_URL=`) | Remove it or set it to the API's URL; empty values are now ignored. |
| Browser: `blocked by CORS policy` on `api.…/api/v1/me` | API doesn't list this app's origin | On the API: `FRONTEND_WEB_URL` / `FRONTEND_ADMIN_URL` (with `https://`, no trailing slash), `SANCTUM_STATEFUL_DOMAINS` (host names), `SESSION_DOMAIN=.<parent domain>`, `SESSION_SECURE_COOKIE=true`; redeploy. |
| API build: `Refusing to boot production with fake/log integration drivers` | `APP_ENV=production` before real SMS, payments, video and Turnstile are configured | Intended. Run the environment as `APP_ENV=staging` until those providers are set. |

## Checking a build locally

```sh
pnpm --filter @yekkola/admin build
cd apps/admin && PORT=3011 node start.mjs   # then open http://localhost:3011
```

## Known limits

- Next.js keeps its data cache and ISR pages on each replica's disk, and every deploy clears them. Fine for now;
  a shared cache handler (Valkey) is a TODO before scaling to several replicas of the web app.
