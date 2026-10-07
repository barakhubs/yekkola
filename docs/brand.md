# Yekkola — Brand

> Source of truth for colour, type, and voice. Tokens live in `packages/ui` (web) and the Flutter theme; both must match this file.

## 1. Colour

### Brand colours

| Role | Hex | Scale step |
|---|---|---|
| Primary — indigo | `#2c3892` | `indigo-800` |
| Secondary — amber | `#fdb73b` | `amber-300` |

### Scales (OKLCH-based, generated from the brand colours)

| Step | Indigo | vs white | Amber | vs white |
|---|---|---|---|---|
| 50 | `#f4f6ff` | 1.08 | `#fef6e9` | 1.07 |
| 100 | `#e7ecfe` | 1.18 | `#faead4` | 1.18 |
| 200 | `#cedafe` | 1.39 | `#ffd391` | 1.40 |
| 300 | `#b1c1fb` | 1.77 | **`#fdb73b`** | 1.75 |
| 400 | `#95a7e8` | 2.34 | `#e09c0f` | 2.35 |
| 500 | `#788bd2` | 3.27 | `#bc8309` | 3.29 |
| 600 | `#5c6ebc` | 4.76 | `#9a6a04` | 4.73 |
| 700 | `#4455a8` | 6.75 | `#7d5501` | 6.62 |
| 800 | **`#2c3892`** | 10.06 | `#644302` | 8.95 |
| 900 | `#212982` | 12.32 | `#4c3200` | 11.91 |
| 950 | `#130f68` | 16.20 | `#322001` | 15.64 |

"vs white" = contrast ratio of that colour against `#ffffff`. AA needs ≥ 4.5 for body text, ≥ 3 for large text and UI components.

### Usage rules

| Use | Light mode | Dark mode (bg `#0f172a`) |
|---|---|---|
| Primary button | bg `indigo-800`, text white (10.1:1) | bg `indigo-300`, text `#0f172a` (10.1:1) |
| Links / interactive text | `indigo-800` | `indigo-300` |
| Hover / pressed | `indigo-900` / `indigo-950` | `indigo-200` / `indigo-100` |
| Subtle surfaces (selected row, info banner) | `indigo-50` / `indigo-100` | `indigo-950` |
| Accent surface ("Gratuit", featured, promo) | bg `amber-300`, text `indigo-800` (5.75:1) or `indigo-900` (7.05:1) | same |
| Amber as text (rare) | `amber-700` (6.6:1) — never `amber-300` | `amber-300` (10.2:1) |
| Rating stars | `amber-300` fill + `amber-500` outline | `amber-300` |
| Focus ring | `indigo-600` (≥ 3:1 on white) | `indigo-300` |

**Never:** white text on amber (1.75:1) · amber-300 text on white · indigo-800 text on dark backgrounds.

### Neutrals & status
Use Tailwind `slate` for neutrals. Status colours (success, warning, danger, info) come from the shadcn defaults, checked for AA; warning must not be confused with the amber brand accent — use `orange`/`yellow` status tones distinct from `amber-300`, always with an icon + label.

### shadcn token mapping

| Token | Light | Dark |
|---|---|---|
| `--primary` / `--primary-foreground` | `indigo-800` / white | `indigo-300` / `#0f172a` |
| `--brand-accent` / `--brand-accent-foreground` | `amber-300` / `indigo-800` | `amber-300` / `indigo-950` |
| `--secondary` | neutral (slate) — shadcn default | neutral |
| `--ring` | `indigo-600` | `indigo-300` |
| Charts | series 1 `indigo-800`, series 2 `amber-300`, then a validated categorical palette | series 1 `indigo-300`, series 2 `amber-300` |

## 2. Typography

**Nunito** for everything (UI, headings, body) on web and mobile.

- Source: Google Fonts (SIL Open Font License). Supports Latin + Latin Extended — all French accents.
- Weights: 400 (body), 600 (emphasis, labels), 700 (headings, buttons), 800 (display/hero only).
- Web: `next/font/google` (`Nunito`, `subsets: ['latin', 'latin-ext']`, `display: 'swap'`, variable font) — self-hosted by Next.js, no runtime request to Google.
- Flutter: bundle the Nunito font files as assets (no runtime download — offline-first); register in `pubspec.yaml`.
- Fallback stack: `Nunito, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`.
- Numerals: use `font-variant-numeric: tabular-nums` in tables, prices, and dashboards.

| Style | Size / line height | Weight |
|---|---|---|
| Display | 36–48 / 1.1 | 800 |
| H1 | 30 / 1.2 | 700 |
| H2 | 24 / 1.25 | 700 |
| H3 | 20 / 1.3 | 700 |
| Body | 16 / 1.5 | 400 |
| Small | 14 / 1.45 | 400 |
| Caption / label | 12 / 1.4 | 600 |

Minimum body size 16 px on mobile web (prevents zoom on input focus, readable on small screens).

## 3. Tagline

| | |
|---|---|
| **Primary (provisional)** | *Les meilleurs profs du Congo, partout au Congo.* — EN: *Congo's best teachers, anywhere in Congo.* |
| Alternate | *Le Congo apprend du Congo.* |
| Offline campaign line | *Même sans réseau, on continue d'apprendre.* |
| Exam-prep line | *Prépare ton Examen d'État avec les meilleurs.* |

Tone: warm, direct, encouraging; **tu** for students, **vous** in professor and legal contexts. Never tie the brand to one city.

## 4. Logo — brief (owner: Yekkola team)

Deliverables, all as SVG + PNG exports:

- Primary lockup (mark + "Yekkola" wordmark), horizontal and stacked
- Mark only (app icon, favicon, avatar)
- Monochrome versions (indigo, white, black)
- App icons: Android adaptive (foreground/background), iOS (1024×1024, no transparency), favicon set (16/32/180/512), PWA maskable
- Clear-space and minimum-size rules

Constraints: works at 16 px, legible on indigo and on white, amber used as an accent only, wordmark in Nunito (800) or a custom drawing derived from it.

Files go in `packages/ui/brand/` (web) and `mobile/assets/brand/` (app).

## 5. Imagery & voice

- Real Congolese learners and teachers from across provinces; avoid stock imagery that signals a single city.
- Copy is French-first; English is a full translation, not an afterthought.
- Data-cost honesty: show sizes, prefer "Télécharger (45 Mo)" over vague CTAs.
