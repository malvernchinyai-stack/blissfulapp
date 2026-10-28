# Release readiness — 28 October 2026

## Summary
| | Status |
|---|---|
| Backend API, database, jobs, admin dashboard, web pages | **Implemented and tested** (78 automated tests passing) |
| Android app (all screens wired to the real API) | **Implemented, not yet compiled or run** |
| Staging at app.blissfulevents.co.zw | **Prepared, not deployed** (no hosting/DNS access) |
| Payments | **Sandbox only, tested.** Paynow adapter written, untested against Paynow; live disabled |
| Push notifications | Implemented; needs a Firebase project; in-app inbox works without it |

## Blockers for the test APK (in order)
- [ ] **GitHub repository**: connect GitHub to Claude (claude.ai → Settings → Connectors) and create an empty private repo, then push this project.
- [ ] **First Android CI run**: fix any compile/lint errors until `Android CI` is green and produces `blissful-gigs-testing-apk`.
- [ ] Emulator smoke test passes in CI.

## Blockers for staging (app.blissfulevents.co.zw)
- [ ] DirectAdmin: confirm `blissfulevents.co.zw` is on the account, PHP 8.2+, SSH access, cron enabled.
- [ ] DNS: `A app → server IP` (if DNS is not on the DirectAdmin server).
- [ ] Staging database + user created; `.env` filled from `.env.example`.
- [ ] Let's Encrypt certificate issued; HTTPS forced.
- [ ] Migrations, sample data, first superadmin with authenticator enrolled.
- [ ] Cron jobs (every minute + daily backup) running.
- [ ] Mail: verification email arrives (check SPF/DKIM if it lands in spam).
- [ ] GitHub `staging` environment secrets for the deploy workflow.
- [ ] Deployment checks in docs/DEPLOYMENT.md §7 pass over HTTPS.
- [ ] Test APK uploaded; tester download password set.
- [ ] **Core journeys completed on a real phone against staging**, results recorded in docs/TEST_REPORT.md.
- [ ] Restore drill repeated on the server.

## Business decisions still needed
- [ ] Confirm brand: app name "Blissful Gigs" and colours (plum #3B1F4A / gold #E0A526) vs Blissful's official palette and logo.
- [ ] Confirm 10% client fee, cancellation tiers (72h / 24h), 48h dispute window, 48h auto-complete.
- [ ] Musician cancellation penalties (currently none beyond losing the booking).
- [ ] Payout timing and method for musicians; who in finance processes them.
- [ ] Add ZiG? (schema supports more currencies; Paynow USD/ZiG rules to confirm).
- [ ] What "approved music service providers" means: should musicians need staff approval before offering?
- [ ] Support email address and response times.

## Legal / external approvals
- [ ] Privacy policy and terms reviewed by a Zimbabwean legal practitioner (Cyber and Data Protection Act; POTRAZ).
- [ ] Paynow merchant account, integration keys, refund process confirmed.
- [ ] Accountant: VAT on fees, payouts to independent musicians.
- [ ] Google Play developer account, data-safety form, content rating, privacy-policy URL on production domain.

## Before production (not authorised yet — do not perform without written approval)
- [ ] Separate production hosting, database, `.env`, domain, and `productionApiBaseUrl` for release builds.
- [ ] Release signing key generated and stored securely (add `ANDROID_KEYSTORE_*` secrets) → signed AAB.
- [ ] `PAYMENT_PROVIDER=paynow`, `PAYNOW_MODE=live`, `PAYMENTS_LIVE_ENABLED=true` — only after go-live authorisation.
- [ ] Independent penetration test; error monitoring (e.g. Sentry); uptime monitor.
- [ ] Remove sample data; seed real instrument list.
- [ ] Publish to Google Play — only with explicit authorisation.
