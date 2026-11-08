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

## API workers and scheduler

- Queue workers must listen on `payments,otp,default,notifications,media` (same order as `config/horizon.php`), or
  notifications and other jobs never run.
- The scheduler must run (`php artisan schedule:run` every minute): payment reconciliation, payouts, the ledger
  check, the professor digest and staff alerts depend on it.
- Push notifications: `PUSH_DRIVER=fcm` with `FCM_PROJECT_ID`, `FCM_CLIENT_EMAIL`, `FCM_PRIVATE_KEY` (from a Firebase
  service account; keep `
` in the key). Without them, leave `PUSH_DRIVER=log` (staging only).

## Troubleshooting (seen on the first deploys)

| Symptom | Cause | Fix |
| --- | --- | --- |
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
