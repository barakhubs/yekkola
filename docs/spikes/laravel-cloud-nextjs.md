# Spike — Next.js + Laravel API from one repo on Laravel Cloud

**Goal:** confirm the monorepo deployment model in `project-context.md` → Repository works before Phase 2.

**Needs:** Laravel Cloud account (org + one environment), the GitHub repo connected.

## Steps

1. On branch `spike/cloud-monorepo`, add a minimal Next.js app in `apps/web` that imports a trivial component from `packages/ui` via the pnpm workspace, and a minimal Laravel app in `api/` with one `GET /api/v1/ping` route.
2. Create Cloud app **yekkola-api** → root directory `api/`, PHP runtime. Deploy; hit `/api/v1/ping`.
3. Create Cloud app **yekkola-web** → root directory `apps/web/`, Node 20+. Build command from repo root: `corepack enable && pnpm install --frozen-lockfile && pnpm turbo build --filter=web`. Deploy.
4. Enable a server-rendered page that fetches `/api/v1/ping` server-side, and one ISR page with `revalidate`.
5. Push a change touching only `api/` — observe whether **yekkola-web** also redeploys.
6. Attach custom domains on one parent domain (`api.` / `www.`) and confirm Sanctum-style cookies work across subdomains (set a cookie from `api.` with `Domain=.parent`, read it on `www.`).

## Pass criteria

- [ ] Both apps deploy from subfolders of one repo
- [ ] Workspace package resolves in the Next.js build
- [ ] SSR and ISR pages work
- [ ] Deploy trigger behaviour recorded (all apps vs changed app) → adjust CI (deploy hooks + path filters) if needed
- [ ] Cross-subdomain cookie works

## Fallback

If Next.js hosting falls short: deploy `apps/web` and `apps/admin` to Vercel; everything else unchanged.

## Results

_Not run yet._
