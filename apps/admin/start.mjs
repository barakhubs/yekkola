// Production start (Laravel Cloud's default `npm run start`, and `pnpm start` locally): the self-contained server
// `next build` writes with `output: "standalone"`, so only this folder needs deploying.
process.env.PORT ??= "3001";
// Containers set HOSTNAME to their own name; listen on every interface instead.
process.env.HOSTNAME = "0.0.0.0";

await import("./.next/standalone/apps/admin/server.js");
