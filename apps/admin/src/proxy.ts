import createMiddleware from "next-intl/middleware";

import { routing } from "./i18n/routing";

export default createMiddleware(routing);

export const config = {
  // Everything except Next internals, the /up health check and files with an extension (favicon, robots.txt…).
  matcher: "/((?!api|up$|_next|_vercel|.*\\..*).*)",
};
