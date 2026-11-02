// After `next build` with `output: "standalone"`: copy the files the standalone server serves but doesn't trace —
// `.next/static` and `public/` — into the app's standalone folder (Next.js docs, output: standalone).
// Run from the app's directory: `node ../../scripts/standalone-assets.mjs <app>` (e.g. admin, web).
import { cpSync, existsSync } from "node:fs";
import { join } from "node:path";

const app = process.argv[2];
if (!app) {
  console.error("Usage: node ../../scripts/standalone-assets.mjs <app>");
  process.exit(1);
}

// In this monorepo the standalone tree mirrors the repo: .next/standalone/apps/<app>/server.js
const target = join(".next", "standalone", "apps", app);
if (!existsSync(join(target, "server.js"))) {
  console.error(`No standalone server at ${target}: is output "standalone" set in next.config?`);
  process.exit(1);
}

cpSync(join(".next", "static"), join(target, ".next", "static"), { recursive: true });
if (existsSync("public")) {
  cpSync("public", join(target, "public"), { recursive: true });
}
console.log(`Standalone assets copied into ${target}`);
