/** Health check for Laravel Cloud (its default path, like Laravel's /up): the server is up and serving. */
export const dynamic = "force-static";

export function GET() {
  return new Response("ok", { headers: { "Cache-Control": "no-store" } });
}
