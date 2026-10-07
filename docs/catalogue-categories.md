# Launch category tree (provisional)

> Status: **provisional default** — seeded at launch, fully editable by admins in the back office (PRD-04, PRD-09). Confirm with the content team (TODO 0.5).
> Covers both tracks — academic and professional/vocational — so professor recruitment isn't limited to one.

Two levels max. `slug` is stable; names are `fr` / `en`.

## Académique / Academic

| Slug | FR | EN |
|---|---|---|
| `exetat` | Examen d'État (Exetat) | State Exam (Exetat) |
| `exetat-sciences` | └ Sciences (Maths, Physique, Chimie, Biologie) | └ Sciences |
| `exetat-lettres` | └ Lettres & Langues | └ Humanities & Languages |
| `exetat-commerciale` | └ Section commerciale & gestion | └ Commerce & Management stream |
| `secondaire` | Secondaire (cours par classe) | Secondary school (by grade) |
| `mathematiques` | Mathématiques | Mathematics |
| `sciences` | Sciences (Physique, Chimie, Biologie) | Sciences |
| `francais` | Français | French |
| `anglais` | Anglais | English |
| `langues-nationales` | Langues nationales (Lingala, Swahili, Kikongo, Tshiluba) | National languages |
| `universite` | Université & préparation aux concours | University & entrance exams |
| `droit` | Droit | Law |
| `medecine-sante` | Médecine & santé | Medicine & health |
| `economie` | Économie | Economics |

## Professionnel / Professional

| Slug | FR | EN |
|---|---|---|
| `informatique` | Informatique & programmation | IT & programming |
| `bureautique` | Bureautique (Word, Excel…) | Office skills |
| `entrepreneuriat` | Entrepreneuriat & business | Entrepreneurship & business |
| `comptabilite-finance` | Comptabilité & finance | Accounting & finance |
| `marketing-digital` | Marketing digital | Digital marketing |
| `agriculture` | Agriculture & agro-business | Agriculture & agribusiness |
| `metiers-techniques` | Métiers techniques (électricité, mécanique, BTP) | Technical trades |
| `sante-communautaire` | Santé communautaire | Community health |
| `developpement-personnel` | Développement personnel | Personal development |

## Audience tags (separate from categories)

Used as filters across categories (`courses.audience`): `exetat`, `secondaire`, `universite`, `professionnel`.

## Notes

- Seed as a Laravel seeder from this table in Phase 1.6; after launch the database is the source of truth, not this file.
- Never add city/province-specific categories.
