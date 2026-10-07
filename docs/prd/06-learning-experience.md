# PRD-06 — Learning Experience

| | |
|---|---|
| Status | Draft |
| Phase | 1 (API), 2 (web), 3 (mobile) |
| Related | PRD-03, PRD-07, PRD-10, architecture §1.3-D/F, §3.5 |

## 1. Summary

Enrolled students learn through video, audio, documents, and quizzes; their progress is saved everywhere (including offline on mobile); they earn **certificates**, leave **reviews**, and ask the professor questions in **lesson Q&A**. Quizzes are central for exam-prep students.

## 2. Goals & non-goals

**Goals**
- Students always resume where they left off, on any device.
- Quizzes give immediate, explained feedback.
- Completion is motivating and verifiable (certificates).

**Non-goals**
- Live classes, group chat, gamification (points/leaderboards) — not at launch.
- Graded assignments with professor marking — not at launch.

## 3. User stories

1. As a student, I open "My courses" and continue the last lesson with one tap.
2. As a student, I watch/listen with speed control (0.75×–2×), quality choice, and resume position.
3. As a student, I read a PDF lesson in the app/web viewer or download it (stamped).
4. As a student, I take a quiz, get my score and explanations, and retake it if allowed.
5. As a student, I see my progress per course and section.
6. As a student, I receive a certificate when I complete a course and share its verification link.
7. As a student, I rate and review a course and edit my review later.
8. As a student, I ask a question on a lesson and get notified when the professor answers.
9. As a professor, I answer questions and reply to reviews from my studio.

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | My courses: list with progress %, last activity, continue button; filters (in progress, completed). | Must |
| FR-02 | Learning view: curriculum sidebar (status per lesson), lesson content, next/previous, mark complete (documents), Q&A tab. | Must |
| FR-03 | Player: Mux Player with DRM tokens from API; speed control; captions if available (Could); per-viewer watermark overlay (PRD-07). | Must |
| FR-04 | Auto-complete rule: video/audio complete at ≥ 90% watched; document complete when opened + marked done; quiz complete when passed. | Must |
| FR-05 | Progress saved every 15 s and on pause/end/leave; batch sync endpoint; monotonic merge (completion never regresses; furthest position wins). | Must |
| FR-06 | Document viewer: in-browser/in-app PDF viewer on stamped file; download button (stamped). | Must |
| FR-07 | Quiz attempt: questions (shuffled if set) without answers → submit → score, pass/fail, correct answers + explanations; time limit enforced server-side with grace; max attempts enforced. | Must |
| FR-08 | Offline quizzes (mobile): questions cached with the course; attempt graded on submit sync. Answer keys are not shipped to devices — offline attempts show results after sync. | Should |
| FR-09 | Course progress = completed required lessons / total lessons of live version. | Must |
| FR-10 | Certificate: issued automatically when the completion rule (setting) is met; PDF (FR/EN per student locale) with serial + QR to the verification page. | Must |
| FR-11 | Certificate snapshot of names/title; revoked if enrollment revoked (refund/fraud); verification page shows revoked. | Must |
| FR-12 | Reviews: rating 1–5 + optional text; only enrolled students; after ≥ 20% progress (setting) to reduce drive-by reviews; one per course, editable; professor public reply. | Must |
| FR-13 | Lesson Q&A: threads per lesson (title + body), replies, professor replies highlighted, status open/answered; notifications to professor (new question) and student (answer). | Must |
| FR-14 | Report review/thread/reply (PRD-09 moderation). | Must |
| FR-15 | Course updated notice: when a new version goes live, enrolled students see "Updated" with a short changelog (professor-provided, Should). | Should |
| FR-16 | Completed-course celebration screen with certificate + review prompt. | Should |

## 5. Business rules

- **BR-01** Every content access goes through an active enrollment check (or preview flag).
- **BR-02** Progress is keyed by `lesson_uid` so it survives versions; removed lessons don't count toward completion.
- **BR-03** Reviews and Q&A from banned users are hidden.
- **BR-04** Professors cannot review their own courses or enroll for ratings.

## 6. UX notes

- "Continue" is the primary action everywhere; lesson type icons with data hints (audio = low data).
- Quiz result screen explains each wrong answer — this is the learning moment for exam prep.
- Q&A on mobile is readable offline (cached) and postable when back online.

## 7. Edge cases

- Same lesson watched on two devices → furthest position wins; completion from either counts.
- Clock skew on device → server uses its own time for completion timestamps; client time only for ordering within a batch.
- Quiz edited in a new version while a student has an open attempt → attempt graded against the version it started on.

## 8. Acceptance criteria

- [ ] Resume position is restored on another device within one sync.
- [ ] A completed lesson never becomes incomplete because of an older sync.
- [ ] Certificate is issued once when the rule is met and verifiable by serial.
- [ ] Students never receive answer keys before submitting.
- [ ] Only enrolled students past the progress threshold can post a review.

## 9. Analytics events

`lesson_started {type}`, `lesson_completed {type}`, `playback_error`, `quiz_started`, `quiz_submitted {score, passed}`, `course_completed`, `certificate_issued`, `certificate_shared`, `review_posted {rating}`, `question_posted`, `question_answered {hours_to_answer}`.

## 10. Open questions

- Captions/subtitles: rely on Mux auto-generated captions (French support?) — **Open**, verify.
- Should quizzes be standalone purchasable "practice exams"? — **Open** (product idea for exam prep).
