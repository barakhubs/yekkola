# PRD-04 — Discovery & Catalogue

| | |
|---|---|
| Status | Draft |
| Phase | 1 (API), 2 (web), 3 (mobile) |
| Related | PRD-03, PRD-05, PRD-06 (reviews), architecture §5.2 |

## 1. Summary

Students find courses through the home page, categories, search, and professor profiles — on the web (SEO-friendly, fast on slow connections) and in the app. Course pages must sell the course honestly: what you'll learn, who teaches it, curriculum, preview lessons, reviews, price or "Gratuit", and download size.

## 2. Goals & non-goals

**Goals**
- Public pages rank in search engines for French queries (subject, exam, professor name).
- Fast first load on 3G; usable on low-end phones.
- Students can tell quickly whether a course is right for them (level, language, audience, previews, reviews).

**Non-goals**
- Personalised ML recommendations — simple rules (popular, new, same category) at launch.
- User-generated lists/collections other than the wishlist.

## 3. User stories

1. As a visitor, I see featured, popular, new, and free courses on the home page.
2. As a student, I browse by category and filter by price (free/paid), language, level, and audience (e.g. Exetat, university).
3. As a student, I search by keyword (title, subject, professor) with typo tolerance in French.
4. As a student, I open a course page and see outcomes, curriculum, previews, professor bio, reviews, price, total duration, and estimated download size.
5. As a student, I watch a free preview lesson without buying.
6. As a student, I open a professor's page and see their courses and rating.
7. As a student, I save courses to a wishlist.
8. As anyone, I verify a certificate by its serial number.

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | Home rails: featured (admin-curated), popular (by enrollments, 30 days), new, free, by category. | Must |
| FR-02 | Category pages with subcategories, course grid, filters, sort (popular, newest, rating, price). | Must |
| FR-03 | Search via Meilisearch: title, subtitle, outcomes, professor name, category, audience tags; French stemming/typo tolerance; filters as FR-02; suggestions as you type (Should). | Must |
| FR-04 | Course card: cover, title, professor, rating, enrollment count, price or "Gratuit", duration, language badge. | Must |
| FR-05 | Course page: hero, outcomes, requirements, curriculum (sections + lessons with type icon, duration, preview flag), professor block, reviews summary + list, price/CTA, total duration, estimated download size (lowest tier), language, level, last updated. | Must |
| FR-06 | Preview playback for lessons marked preview — no login required on web; tokens issued for preview lessons only. | Must |
| FR-07 | Professor page: photo, headline, bio, verified badge, province (if set), rating, students, courses. | Must |
| FR-08 | Wishlist (logged-in): add/remove from card and course page; wishlist page. | Should |
| FR-09 | Certificate verification page: serial → student name, course, professor, issue date, valid/revoked. | Must |
| FR-10 | SEO: SSR/ISR pages, localized metadata, canonical URLs, `hreflang` fr/en, JSON-LD (`Course`, `Person`, `BreadcrumbList`), sitemap, robots. | Must |
| FR-11 | Index updates within 1 minute of a course being published/unpublished or its price changing. | Must |
| FR-12 | Unpublished, archived, or taken-down courses: 404 for new visitors (enrolled students reach them from "My courses"). | Must |
| FR-13 | Share buttons (WhatsApp first, copy link) on course and professor pages. | Should |
| FR-14 | Mobile app: same catalogue, cached for offline browsing of previously loaded pages. | Must (phase 3) |

## 5. Business rules

- **BR-01** Only `published` courses from `active` professors appear in catalogue and search.
- **BR-02** Ratings displayed only when a course has ≥ 3 reviews (otherwise "Nouveau").
- **BR-03** "Featured" is admin-controlled only (PRD-09); ordering is manual.
- **BR-04** No location-based ranking that privileges any city or province.

## 6. UX notes

- Mobile-first layout; images lazy-loaded and sized responsively; no autoplay video on public pages.
- "Gratuit" badge is prominent; "Low data" hints on audio/document lessons.
- Empty and zero-result states suggest categories and free courses.

## 7. Edge cases

- Course price changes while a student is on the page → checkout quote always reflects the current price (PRD-05).
- Search service down → fall back to database query for category browsing; search shows a friendly error.

## 8. Acceptance criteria

- [ ] Public course page renders server-side with correct metadata and JSON-LD.
- [ ] Lighthouse mobile performance ≥ 85 on home, category, and course pages (slow 4G profile), LCP < 2.5 s on slow 3G target.
- [ ] Searching a misspelled French word returns relevant results.
- [ ] Preview lesson plays without an account; non-preview lessons require enrollment.
- [ ] Published course appears in search within 1 minute.

## 9. Analytics events

`home_viewed`, `category_viewed`, `search_performed {query_length, results}`, `search_result_clicked`, `course_viewed {source}`, `preview_played`, `professor_viewed`, `wishlist_added`, `certificate_verified`.

## 10. Open questions

- Category tree at launch (academic vs professional/vocational) — admin decides in the back office; content team to propose.
