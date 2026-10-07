# Yekkola — Product Requirements (PRDs)

One PRD per product area. Each PRD states goals, user stories, functional requirements (`FR-xx`), business rules (`BR-xx`), edge cases, acceptance criteria, and analytics events. Technical shapes (tables, endpoints) are in [`../architecture.md`](../architecture.md); product principles in [`../../project-context.md`](../../project-context.md).

| # | PRD | Primary users | Build phase |
|---|---|---|---|
| 01 | [Auth & identity](01-auth-identity.md) | Everyone | 1 (API), 2 (web), 3 (mobile) |
| 02 | [Professor onboarding](02-professor-onboarding.md) | Professors, admins | 1, 2 |
| 03 | [Course authoring](03-course-authoring.md) | Professors | 1, 2 |
| 04 | [Discovery & catalogue](04-discovery-catalogue.md) | Students, visitors | 1, 2, 3 |
| 05 | [Checkout & payments](05-checkout-payments.md) | Students, parents | 1 (fake gateway), 2, 3; real gateway later |
| 06 | [Learning experience](06-learning-experience.md) | Students, professors | 1, 2, 3 |
| 07 | [Content protection & offline](07-content-protection-offline.md) | Students, professors | 1, 2, 3 |
| 08 | [Earnings & payouts](08-earnings-payouts.md) | Professors, finance admins | 1, 2 |
| 09 | [Back office](09-back-office.md) | Admins, moderators | 1, 2 |
| 10 | [Notifications](10-notifications.md) | Everyone | 1, 2, 3; real SMS later |

## Conventions

- **Must / Should / Could** priority on each requirement. "Must" = required for public launch.
- Requirement IDs are stable — reference them in tickets and PRs (e.g. `PRD-05 FR-07`).
- Analytics event names use `snake_case` `object_action` (e.g. `course_enrolled`).
- Anything marked **Open** is not decided — do not invent a value in code; ask.
