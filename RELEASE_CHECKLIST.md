# Release readiness — 28 October 2026

## Summary
| | Status |
|---|---|
| Backend API, database, jobs, admin dashboard, web pages | **Implemented and tested** (78 automated tests passing) |
| Android app (all screens wired to the real API) | **Builds in CI; unit, lint and emulator smoke tests pass** (30 Oct); not yet tried on a phone |
| Staging at app.blissfulevents.co.zw | **Deployed 28 Oct 2026** — backend live over HTTPS; Android app not yet tested against it |
| Payments | **Sandbox only, tested.** Paynow adapter written, untested against Paynow; live disabled |
| Push notifications | Implemented; needs a Firebase project; in-app inbox works without it |

## Blockers for the test APK (in order)
- [x] **GitHub repository**: `malvernchinyai-stack/blissfulapp` (pushed 30 Oct).
- [x] **First Android CI run** green (30 Oct); APK published to the `test-builds` branch.
- [x] Emulator smoke test passes in CI.

## Blockers for staging (app.blissfulevents.co.zw)
- [x] Domain is on the Blissful DirectAdmin account; SSH available (confirmed 28 Oct). DNS is automatic.
- [x] Subdomain `app` (docroot `domains/app.blissfulevents.co.zw/public_html`), database `blisszhq_blissapp`, PHP 8.5 via CloudLinux selector, existing wildcard certificate.
- [x] Installer run 28 Oct: `.env` written on server, 5 migrations, sample data, web files, cron jobs installed.
- [ ] First superadmin created (`bin/create-admin.php`) and authenticator enrolled.
- [ ] Rotate the staging DB password (it was shared in chat) and update `DB_PASS` in `.env`.
- [ ] Mail: verification email arrives (check SPF/DKIM if it lands in spam).
- [ ] GitHub `staging` environment secrets for the deploy workflow.
- [x] Deployment checks pass over HTTPS (28 Oct): /health OK, /api/catalog 200, /admin 303, /.app-path 403, http→https 301; login rejects bad credentials.
- [ ] Test APK uploaded to `storage/app/` on the server (tester download password set: yes).
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
