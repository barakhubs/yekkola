/** @type {import("prettier").Config} */
export default {
  printWidth: 110,
  singleQuote: false,
  trailingComma: "all",
  plugins: ["prettier-plugin-tailwindcss"],
  // Tailwind v4: class order comes from the shared theme.
  tailwindStylesheet: new URL("../ui/src/styles/globals.css", import.meta.url).pathname.replace(
    /^\/([A-Za-z]:)/,
    "$1",
  ),
};
