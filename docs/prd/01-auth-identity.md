# PRD-01 — Auth & Identity

| | |
|---|---|
| Status | Draft |
| Phase | 1 (API), 2 (web), 3 (mobile) |
| Related | PRD-07 (devices), PRD-10 (SMS), architecture §1.3-A, §3.1 |

## 1. Summary

Students and professors sign in with their **phone number and a one-time SMS code** — no passwords. Phones are how DRC users pay, so the phone number is their identity. Each user can register a limited number of mobile devices; web sessions are separate. Staff use the same mechanism, with stronger controls.

## 2. Goals & non-goals

**Goals**
- Sign-up and sign-in in under 60 seconds on a slow network.
- One account per phone number; the same account works on web and mobile.
- Strong protection against OTP abuse (SMS pumping) and account takeover.

**Non-goals**
- Passwords, social login (Google/Facebook) — not at launch.
- Multiple phone numbers per account.

## 3. User stories

1. As a new visitor, I enter my phone number, receive a code, and I'm signed in — an account is created automatically.
2. As a returning user, I sign in on a new phone and my courses are there.
3. As a user, I can add my name and (optionally) email, choose French or English, and optionally my province.
4. As a user, I can see my registered devices and remove one I no longer use.
5. As a user, I can change my phone number after verifying the new one.
6. As a user, I can download my data or delete my account.
7. As an admin, I can suspend or ban an account, which signs it out everywhere.

## 4. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-01 | Phone input with DRC (+243) default, accepting other country codes; normalized to E.164. | Must |
| FR-02 | `Request OTP`: 6-digit code, 5-minute expiry, sent via `SmsSender`. Resend allowed after 60 s. | Must |
| FR-03 | `Verify OTP`: max 5 attempts per challenge; success creates the user if new. | Must |
| FR-04 | First sign-in collects name (required) and locale; email and province optional. | Must |
| FR-05 | Web: Sanctum session cookie on the parent domain (shared by web app; admin uses its own session). | Must |
| FR-06 | Mobile: token bound to a `Device` (install ID, platform, model, app version, push token). | Must |
| FR-07 | Device list and removal; removal revokes that device's token and offline licenses (PRD-07). | Must |
| FR-08 | Registering a device beyond the limit returns `device.limit_reached` with the current device list; the user retries verify with the **same code** plus `replace_device_id` to sign that device out. | Must |
| FR-09 | Change phone: OTP to new number, then all other sessions/tokens revoked. | Must |
| FR-10 | Rate limits: 3 OTP requests / 10 min per phone; 10 / hour per IP; 60 s resend cooldown; bot challenge (Turnstile) on web OTP request. Mobile requests get app attestation (Play Integrity / App Attest) in phase 3. | Must |
| FR-11 | Account deletion: request → 14-day grace → anonymize personal data; keep financial records (legal requirement) linked to an anonymized ID. | Must |
| FR-12 | Data export: JSON/ZIP of profile, enrollments, orders, certificates, delivered by link. | Should |
| FR-13 | Roles: student (default), professor (after approval, PRD-02), moderator, admin (+ permission sets e.g. `finance.*`). | Must |
| FR-14 | Staff sign-in to admin requires an active staff role; sessions expire after 12 h; optional second factor (TOTP) for admins. | Should (TOTP: Should) |
| FR-15 | Suspended users can sign in but only see an explanation screen; banned users cannot sign in. | Must |
| FR-16 | `SmsSender` log driver in local/staging; production refuses to boot with it (see project-context). | Must |

## 5. Business rules

- **BR-01** One phone number = one account. Phone numbers freed by deletion can be reused after the grace period.
- **BR-02** OTP codes are stored hashed, never logged in production.
- **BR-03** Registered device limit and concurrent web stream limit come from platform settings.
- **BR-04** Minimum age: **Open** (see project-context). Until decided, collect no date of birth.

## 6. UX notes

- Two-step screen: phone → code. Auto-advance on 6 digits; Android SMS autofill (SMS Retriever) in the app.
- Clear error states: wrong code (attempts left), expired code, too many requests (wait time), network error.
- Copy in FR/EN; phone formatting as the user types.

## 7. Edge cases

- SMS never arrives → resend after 60 s; after 3 failed deliveries show support contact.
- User switches SIM / loses phone → change-phone flow requires access to the new number; recovery via support if the old number is lost (manual, audited).
- Same phone signs in on a 3rd device → limit flow (FR-08).
- OTP requested from many numbers by one IP → IP limit + challenge.

## 8. Acceptance criteria

- [ ] New user signs up with phone + OTP and lands on the home screen with name set.
- [ ] Wrong code 5 times invalidates the challenge.
- [ ] 4th OTP request within 10 min for one phone is rejected with a wait time.
- [ ] Removing a device invalidates its token (next API call returns 401).
- [ ] Banned user cannot obtain a session or token.
- [ ] Production boot fails if `SmsSender` is the log driver.

## 9. Analytics events

`otp_requested`, `otp_verified`, `otp_failed {reason}`, `signup_completed`, `login_completed {client}`, `device_registered`, `device_removed`, `device_limit_reached`, `account_deletion_requested`.

## 10. Dependencies & open questions

- SMS gateway selection and DRC deliverability (deferred; see project-context).
- Minimum age policy — **Open**.
