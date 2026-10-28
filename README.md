# Blissful Gigs — by Blissful Studios

A marketplace that connects musicians with churches, event planners, venues, businesses and individuals.
Clients post a request with a budget, musicians send offers, the client compares and books, then both sides
chat, pay, complete and review.

> **Status (28 Oct 2026):** backend + admin are built and tested (78 passing tests). The Android app is written
> but **not yet compiled** (needs GitHub Actions). Staging is **prepared, not deployed**. See `RELEASE_CHECKLIST.md`.

## Repository
```
backend/      PHP 8.2+ API, admin dashboard, web pages, migrations, jobs, tests
android/      Kotlin + Jetpack Compose + Material 3 app
docs/         ARCHITECTURE, DEPLOYMENT, SECURITY, PAYMENTS, OPERATIONS, TEST_REPORT
.github/      CI for backend and Android, manual staging deploy
```

## Run the backend locally
Requirements: PHP 8.2+ (pdo_mysql, sodium, gd, fileinfo, curl, mbstring), MariaDB 10.6+/MySQL 8, PHPUnit 9.6.
```bash
cd backend
cp .env.example .env          # set APP_ENV=development, APP_URL=http://localhost:8080, DB_*, MAIL_DRIVER=log
php bin/migrate.php
SEED_PASSWORD='SampleTest2026' php bin/seed.php      # fictional sample accounts
php bin/create-admin.php you@example.com "You" superadmin
php -S 127.0.0.1:8080 -t public bin/dev-router.php   # http://localhost:8080 , /admin
php bin/cron.php -v                                  # run scheduled jobs once
```
Verification and reset codes are written to `storage/mail/` when `MAIL_DRIVER=log`.

Tests: create `backend/tests/.env.test` (see `.github/workflows/backend.yml` for the template) pointing at an empty
test database, then `phpunit`. The suite rebuilds the test schema on each run.

## Build the Android app
Android Studio Ladybug+ or CI. `./gradlew assembleStaging` builds the test APK that talks to
`https://app.blissfulevents.co.zw/api/`. For a local backend on the emulator:
`./gradlew installDebug -PapiBaseUrl=http://10.0.2.2:8080/api/`.

## Key design points
- Booking acceptance is a single database transaction with row locks; tested with parallel processes.
- Money is integer cents everywhere; times stored in UTC and shown in the event's time zone.
- Private details (exact address, phone) are only shared between booked parties.
- Payments: sandbox now; Paynow adapter ready; live payments disabled until authorised.
