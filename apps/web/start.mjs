// Production start (Laravel Cloud's default `npm run start`, and `pnpm start` locally): the self-contained server
// `next build` writes with `output: "standalone"`, so only this folder needs deploying.
process.env.PORT ??= "3000";
// Containers set HOSTNAME to their own name; listen on every interface instead. "::" covers IPv4 and IPv6:
// Laravel Cloud's startup probes come over IPv6, so "0.0.0.0" (IPv4 only) fails the deploy.
process.env.HOSTNAME = "::";

await import("./.next/standalone/apps/web/server.js");
