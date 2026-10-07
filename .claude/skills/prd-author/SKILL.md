---
name: prd-author
description: Write or update a Yekkola PRD in docs/prd/ using the project template (goals, user stories, FR/BR tables, edge cases, acceptance criteria, analytics events, open questions). Use when asked to spec, scope, or document a feature.
---

# Write or update a PRD

1. Read `project-context.md` and `docs/prd/README.md`. Check whether an existing PRD already covers the area — prefer updating it over creating a new one.
2. New PRD file: `docs/prd/NN-kebab-name.md` (next number), and add it to the table in `docs/prd/README.md`.
3. Use this structure:
   - Header table: Status, Phase, Related
   - 1 Summary · 2 Goals & non-goals · 3 User stories · 4 Functional requirements (`FR-NN`, Must/Should/Could) · 5 Business rules (`BR-NN`) · 6 UX notes · 7 Edge cases · 8 Acceptance criteria (checkboxes) · 9 Analytics events (`object_action`) · 10 Open questions
4. **Never renumber** existing FR/BR IDs — they're referenced by tickets and commits. Deprecate with ~~strikethrough~~ and a note instead.
5. Anything not decided → mark **Open**; don't invent values. Values the admin controls → reference *Platform settings* instead of hard numbers.
6. Respect product principles: full product (not pilot), not tied to any city, French first, mobile/offline-first, data-cost aware.
7. If the PRD implies schema or endpoint changes, update `docs/architecture.md` accordingly.
