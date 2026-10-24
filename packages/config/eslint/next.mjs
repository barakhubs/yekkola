import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";
import prettier from "eslint-config-prettier";

/**
 * Next.js apps: Next's Core Web Vitals + TypeScript rules. Components never call `fetch` directly — server
 * access goes through @yekkola/api-client (docs/architecture.md §5.1).
 */
export default [
  { ignores: [".next/**", "next-env.d.ts"] },
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      "@typescript-eslint/no-unused-vars": ["error", { argsIgnorePattern: "^_", varsIgnorePattern: "^_" }],
      "@typescript-eslint/consistent-type-imports": "error",
      // Locale-aware navigation only (notFound and other helpers from next/navigation stay allowed).
      "no-restricted-imports": [
        "error",
        {
          paths: [
            { name: "next/link", message: "Use Link from @/i18n/navigation." },
            {
              name: "next/navigation",
              importNames: ["redirect", "permanentRedirect", "useRouter", "usePathname"],
              message: "Use the locale-aware versions from @/i18n/navigation.",
            },
          ],
        },
      ],
      "no-restricted-globals": [
        "error",
        { name: "fetch", message: "Use the generated hooks/fetchers from @yekkola/api-client." },
      ],
    },
  },
  prettier,
];
