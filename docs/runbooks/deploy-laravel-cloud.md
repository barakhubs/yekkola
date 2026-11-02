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
  (set by Cloud) and listens on every interface.

## Settings per application

| Setting | `yekkola-admin` | `yekkola-web` |
| --- | --- | --- |
| Root directory | `apps/admin` | `apps/web` |
| Runtime | Node.js 22 or 24 | Node.js 22 or 24 |
| Build command | see below | see below |
| Start command | default (`npm run start`) | default (`npm run start`) |

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

## Checking a build locally

```sh
pnpm --filter @yekkola/admin build
cd apps/admin && PORT=3011 node start.mjs   # then open http://localhost:3011
```

## Known limits

- Next.js keeps its data cache and ISR pages on each replica's disk, and every deploy clears them. Fine for now;
  a shared cache handler (Valkey) is a TODO before scaling to several replicas of the web app.
