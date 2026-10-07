# PRD-03 — Course Authoring

| | |
|---|---|
| Status | Draft |
| Phase | 1 (API), 2 (web studio) |
| Related | PRD-02, PRD-07, PRD-09 (review queue), architecture §1.3-B, §3.3 |

## 1. Summary

Approved professors build courses in the **studio** (web): course details, curriculum of sections and lessons (video, audio, document, quiz), pricing (paid or free), and submit for review. Content is **versioned**: edits to a live course happen in a draft and go live only after approval, without taking the course offline.

## 2. Goals & non-goals

**Goals**
- A professor can go from nothing to a submitted course in one sitting.
- Uploads are reliable on unstable connections (resumable, direct to Mux / storage).
- Live courses are never broken by edits in progress.

**Non-goals**
- Co-authored courses (multiple professors) — not at launch.
- Authoring in the mobile app — studio is web only.
- Live sessions (out of scope).

## 3. User stories

1. As a professor, I create a course with title, subtitle, description, what students will learn, requirements, category, language, level, audience tags (e.g. Exetat), and a cover image.
2. As a professor, I organise lessons into sections and reorder them by drag-and-drop.
3. As a professor, I add a video or audio lesson by uploading a file and see processing status.
4. As a professor, I add a PDF document lesson.
5. As a professor, I build a quiz with single/multiple-choice and true/false questions, explanations, and a pass mark.
6. As a professor, I mark some lessons as free previews.
7. As a professor, I set the price and currency, or make the course free.
8. As a professor, I submit the course for review and see feedback if it's rejected.
9. As a professor, I edit a live course; my changes go live after approval while students keep using the current version.
10. As a professor, I unpublish a course (no new enrollments; existing students keep access).

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | Create course → `Course` + first `CourseVersion` (draft). | Must |
| FR-02 | Details editor with validation (title ≤ 80 chars, subtitle ≤ 120, description rich text, 3–8 outcomes, cover 16:9 ≥ 1280×720). | Must |
| FR-03 | Curriculum editor: sections and lessons CRUD, drag-and-drop reorder (sections and lessons across sections), autosave. | Must |
| FR-04 | Video/audio lesson (video: `plus` quality + DRM, max 720p; audio: signed playback): request Mux direct upload → resumable upload from browser with progress → status `processing → ready / errored`. Max file size per setting (default 4 GB video, 500 MB audio). | Must |
| FR-05 | Burned-in watermark (Yekkola logo + professor name) applied at upload via Mux overlay. | Must |
| FR-06 | Document lesson: PDF upload (≤ 50 MB) via pre-signed URL, virus scan, page count extracted. | Must |
| FR-07 | Quiz builder: questions (single, multiple, true/false), options, correct answers, explanation per question, points, pass mark %, optional time limit, optional max attempts, shuffle toggle. | Must |
| FR-08 | Preview lessons: professor marks lessons as free previews (max per setting, default 3). | Must |
| FR-09 | Pricing: free toggle or price + currency (from enabled currencies); minimum and maximum price per currency (settings). | Must |
| FR-10 | Pre-submit checklist: ≥ 1 section, ≥ 3 lessons, all media `ready`, cover, description, price set; submit blocked until satisfied. | Must |
| FR-11 | Submit → version `in_review`; course `in_review` if never published. Draft is read-only while in review; professor can withdraw. | Must |
| FR-12 | Review outcome (PRD-09): approved → version becomes live, previous live becomes `superseded`; rejected → notes shown, draft editable again. | Must |
| FR-13 | Edit live course → "Start new draft" copies the live version (lessons keep `uid`); media assets reused, not re-uploaded. | Must |
| FR-14 | Version diff view for the professor ("changes since live"). | Should |
| FR-15 | Price and free/paid changes are **course settings**, not version content: they apply immediately without review, going forward only. | Must |
| FR-16 | Unpublish / republish (republish without review if live version unchanged). Archive = hidden everywhere except for enrolled students. | Must |
| FR-17 | Delete: only courses with zero enrollments; otherwise archive. | Must |
| FR-18 | Free-course limits enforced at the moment of making a course free or submitting a free course (setting). | Must |
| FR-19 | Course preview as a student (render the draft in the learning UI). | Should |
| FR-20 | Estimated download sizes per lesson shown to students come from Mux rendition data; studio shows them to professors too. | Should |

## 5. Business rules

- **BR-01** Students always see the live version; drafts are never visible outside the studio and review queue.
- **BR-02** Removing a lesson in a new version removes it for everyone once approved; students' progress on removed lessons is kept but no longer counts toward completion.
- **BR-03** Existing certificates are not affected by later versions.
- **BR-04** A course belongs to exactly one professor; transfer is admin-only.
- **BR-05** Media assets are owned by the professor and deleted from Mux only when no version (live, superseded with enrolled students, or draft) references them.

## 6. UX notes

- Studio course editor tabs: **Details · Curriculum · Pricing · Review**. Status banner at top (Draft, In review, Live, Live + draft in review, Rejected).
- Upload UI tolerates tab switching and resumes after a network drop.
- Quiz builder supports keyboard-only authoring and bulk paste of questions (Should).

## 7. Edge cases

- Upload interrupted → resume; if the upload URL expired, request a new one and restart.
- Mux processing error → lesson shows error with retry/re-upload.
- Professor submits while a media asset is still processing → blocked by checklist.
- Professor suspended while a version is in review → review paused.

## 8. Acceptance criteria

- [ ] A professor can create a course with video, audio, PDF, and quiz lessons and submit it.
- [ ] Editing a live course never changes what students see until the new version is approved.
- [ ] Progress on a lesson survives a new version (same `uid`).
- [ ] Changing price applies to new orders only; existing enrollments untouched.
- [ ] Students never receive correct quiz answers before submitting an attempt.

## 9. Analytics events

`course_created`, `lesson_added {type}`, `media_upload_started {kind}`, `media_upload_completed`, `media_processing_failed`, `course_submitted`, `version_approved`, `version_rejected`, `course_unpublished`, `course_price_changed`.

## 10. Open questions

- Course-level max length or lesson count limits? — **Open**.
- Allow professors to upload downloadable "resources" attached to video lessons (in addition to document lessons)? — **Open** (Could).
