# Test report — 28 October 2026

Only tests that were actually run are reported as passing. Raw output is in `docs/test-results/`.

## Environment
Ubuntu 24.04 · PHP 8.3.6 CLI · MariaDB 10.11.14 · PHPUnit 9.6.17. (The DirectAdmin host must run PHP 8.2+; CI uses 8.2.)

## Backend — RUN, ALL PASSING
**78 tests, 1,058 assertions, 0 failures** (full suite run twice in a row with identical results; concurrency suite also run 3× on its own).

| Area | Tests | What is covered |
|---|---|---|
| Authentication & accounts | 13 | sign-up + email code, no account enumeration (sign-up, login, recovery), weak passwords, code attempt limit, lockout, IP rate limit, refresh rotation + reuse detection, token expiry, reset revokes sessions, one account / two roles, logout, password change, account deletion rules |
| Marketplace | 13 | unavailable days suppress matching, request validation (dates, deadline, duplicates, float money, currency), draft→publish, UTC storage of `+02:00` input, matching by instrument/range/availability, offer rules (own request, wrong instrument, extras need a note), edit/withdraw/resubmit with history, counter-offers needing explicit acceptance + stale-version refusal, acceptance closing positions, multi-position capacity, one place per musician per event, overlap prevention incl. setup time, request cancellation, deadline extension and automatic expiry |
| Concurrency (real parallel OS processes vs MariaDB) | 4 | 8 musicians racing for 1 seat → exactly 1 booking; same offer accepted 6× at once → 1; 7 racing for 2 places → 2; one musician double-booked by 4 clients at overlapping times → 1 |
| Bookings | 11 | visibility, 3 cancellation tiers, musician cancellation (free, place reopens), policy frozen at booking time, reschedule needs other party, reschedule conflict, completion by both parties, reviews (once each, only after completion, no outsiders), dispute window, disputes pausing auto-complete + staff resolution, reminders sent once, auto-complete |
| Payments | 9 | sandbox success, duplicate webhooks, forged signatures, amount mismatch, failure then retry, provider outage reported honestly, refund once per booking, payment arriving after cancellation refunded, payouts after dispute window, Idempotency-Key replay/mismatch, Paynow hash + status mapping, live mode refused without flag |
| Chat & notifications | 5 | participants only, incremental polling, duplicate/oversize messages, spam rate limit + one push per burst, notification de-duplication, preferences, push retry/backoff, dead-token removal, never sent twice, in-app fallback without push |
| Profiles, files, money | 5 | integer money maths/rounding, fee changes not retroactive, search filters, badges only for approved checks, upload type sniffing (PHP-as-image rejected), size cap, private document access, retention date, RFC 6238 TOTP vectors |
| Security | 10 | 401 on all protected routes, cross-user access on 11 endpoints, offer theft, mass assignment, role escalation, bearer token can't reach admin, musician-only routes, blocking, report probing, SQL-injection inputs, log redaction, error leakage, health endpoint content |
| Admin & web | 8 | password + TOTP sign-in, regular users refused, CSRF, role permissions, audited settings, moderation (hide message, suspend → sessions revoked), verification approval → badge, payouts can't be paid twice, public pages, signed sandbox checkout |

## Other checks — RUN
- **Real HTTP smoke test** via PHP's built-in server: health, login, Bearer auth, offers comparison, multipart upload, rejection of a PHP file disguised as PNG, security headers, admin redirect, JSON 404.
- **Split web-root layout** (DirectAdmin style, `.app-path`): `/health` ok and `/api/catalog` 200.
- **Browser check** (headless Chromium): landing page at phone width; admin sign-in incl. authenticator code; dashboard and settings render. Screenshots in `docs/screenshots/`.
- **Backup & restore drill**: see OPERATIONS.md — verified.
- **Seed script** creates sample data through the real services (requests, matching, offers).

## Android — NOT YET RUN
This build environment cannot reach Google's Android SDK / Maven servers, and running a downloaded Kotlin compiler was not permitted, so **the Android code has not been compiled or executed yet.**
Written and wired to run in GitHub Actions (`.github/workflows/android.yml`):
- JVM unit tests: `FormatTest` (6: money, rounding parity with server, event time zones, ISO conversion, error parsing) and `ApiClientTest` (7: token refresh single-flight, rejected refresh signs out, network failure keeps session, idempotency keys, conflict messages, timeouts → offline, snake_case decoding).
- Android lint (fails the build on errors), staging APK build, release bundle when signing secrets exist.
- Emulator UI smoke test `WelcomeFlowTest` (onboarding → sign-up → sign-in).

Expect the first CI run to surface compile errors that need fixing; that iteration has not happened yet.

## Not tested
- Anything against the real staging server (not deployed), real email delivery, Paynow's servers, Firebase push delivery.
- Android end-to-end journeys on a device or emulator against a hosted backend.
- Load/performance beyond the concurrency tests; independent security testing.
