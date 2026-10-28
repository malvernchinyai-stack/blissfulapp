# Blissful Gigs — Architecture

Blissful Gigs is the musician-booking marketplace of **Blissful Studios** (Blissful Events & Facilities, Harare).
Clients publish a request with a proposed budget, musicians respond with offers, the client picks one, and the
platform turns that into a confirmed booking with chat, status tracking, payment and reviews.

## 1. Decisions taken (28 Oct 2026)

| Decision | Choice | Reversible? |
|---|---|---|
| Hosting | Existing **DirectAdmin shared host**, subdomain `app.blissfulevents.co.zw` | Yes — the backend also runs on any PHP 8.2+/MySQL VPS unchanged |
| Backend | **PHP 8.2+ (no framework)** + **MySQL 8 / MariaDB 10.6+** (InnoDB) | Medium |
| Android | **Kotlin, Jetpack Compose, Material 3**, minSdk 26, target/compile 35 | — |
| APK builds | **GitHub Actions** (this build environment cannot reach Google's Android SDK servers) | Yes |
| Market / money | Zimbabwe, **USD**, amounts in integer cents ("minor units") | ZiG can be added as a second currency row |
| Payments | **Paynow** behind a provider interface; **sandbox only**, live disabled | Live needs merchant keys + explicit go-ahead |
| Commission | **10% client service fee** (`platform_fee_bps = 1000`), editable in admin | Yes |
| Cancellation | Free > 72h before start; 50% of agreed price 24–72h; 100% < 24h; musician cancellation always free for the client | Yes, admin-configurable |
| Dispute window | 48 h after completion is confirmed | Yes |

### Why no framework?
Composer's package registry (Packagist) is blocked from this build environment, and shared hosting typically has no
Composer or SSH build step. The backend is therefore written as a small, dependency-free PHP codebase that is uploaded
as-is. Tradeoff: we own the router, validation and auth code ourselves instead of using Laravel. Mitigations:
security-critical primitives come from PHP itself (`password_hash` with Argon2id/bcrypt, `random_bytes`,
`hash_equals`, PDO prepared statements, `sodium` for secret encryption), and every rule is covered by automated tests.
If the project moves to a VPS later, porting to Laravel/Symfony is straightforward because business logic lives in
plain service classes, not in controllers.

### Shared-hosting constraints and how we handle them
| Need | Shared-hosting answer |
|---|---|
| Real-time chat | Short polling (`GET /api/bookings/{id}/messages?after=ID`) every 4 s while a chat is open, 30 s for the badge. No WebSockets. |
| Background jobs | One DirectAdmin cron entry runs `bin/cron.php` every minute: expiry, reminders, notification outbox, auto-complete, payment polling. A DB lock prevents overlapping runs. |
| File storage | Private uploads stored **outside** `public_html` and streamed through an authorised endpoint. |
| Push notifications | Firebase Cloud Messaging HTTP v1 called from the cron worker (outbox pattern, retries, de-duplication). Requires a Firebase project — disabled until configured; in-app history always works. |
| Email | PHP `mail()` (DirectAdmin's Exim) or a `log` driver in development. |

## 2. System overview

```
 Android app (Kotlin/Compose)                 Browser (staff)
        │  HTTPS + Bearer token                      │ HTTPS + session cookie + TOTP
        ▼                                            ▼
 app.blissfulevents.co.zw  ──  public/index.php (front controller)
        ├── /api/*      JSON API  ─┐
        ├── /admin/*    server-rendered admin dashboard
        ├── /health     {"status":"ok"}
        ├── /, /privacy, /terms   static testing pages
        └── /webhooks/paynow      payment result URL
                                   ▼
                      src/Service/* (business rules, transactions)
                                   ▼
                      MySQL/MariaDB (InnoDB, utf8mb4, UTC)
   cron (every minute) → bin/cron.php → jobs + notification outbox → FCM / mail
```

Layers: `Http` (routing, request parsing, auth middleware, JSON responses) → `Service` (all business rules and
authorization checks, transactions) → `Repo`/PDO (queries). Controllers never touch SQL directly for state changes.

## 3. Core state machines (server-enforced)

**Request**: `draft → open → (filled | closed | expired | cancelled)`. Positions inside a request have their own
`open → filled | cancelled` state and a `quantity` / `filled_count`.

**Offer**: `submitted → accepted | rejected | withdrawn | expired`. Editable only while `submitted`. Every create,
edit and counter is written to `offer_revisions`. A client counter-proposal sets `counter_price_minor`; the musician
must explicitly accept it (which writes a new revision and bumps `version`) before the client can accept the offer.

**Booking**: `confirmed → in_progress → completed`, plus `cancelled` and `disputed`.
`Draft` and `Open` in the brief are the request's states before a booking exists.
Completion: either party marks it done after the scheduled end; the booking completes when both confirm, or
automatically 48 h after the end if nobody disputes. A dispute can be raised until the dispute window closes.

**Payment** (independent of booking): `pending → paid | failed | cancelled`, then `refunded | partially_refunded`.

## 4. Concurrency and double-booking

`POST /api/offers/{id}/accept` runs in one transaction:

1. `SELECT … FOR UPDATE` the **musician's profile row** (serialises all bookings for that musician),
   the **position row** and the **offer row**, in that fixed order to avoid deadlocks.
2. Check: offer is `submitted`, `version` equals the version the client saw (explicit acceptance of the exact terms),
   request is `open`, deadline not passed, position `filled_count < quantity`, the musician has no other active booking
   on the same request, and no overlapping `confirmed`/`in_progress` booking (including setup time).
3. Insert booking (a `UNIQUE(offer_id)` constraint is a second safety net), mark offer accepted, increment
   `filled_count`, reject remaining offers on a filled position, mark request `filled` when all positions are full.

Tests fire parallel acceptance requests from separate processes against a real MariaDB to prove only one wins.

## 5. Money and time
* All amounts are `BIGINT` cents with an ISO-4217 `currency` column. Fees: `fee = round_half_up(price × bps / 10000)`
  using integer arithmetic only. No floats anywhere in money paths (enforced by tests).
* All timestamps stored as UTC `DATETIME`; each request stores the event's IANA time zone (default `Africa/Harare`)
  so the app shows local event time correctly and overlap checks run in UTC.

## 6. Security model (summary — see SECURITY.md)
* Passwords: `password_hash` (Argon2id where available). Tokens: 256-bit random, only SHA-256 hashes stored.
  Access token 60 min, rotating refresh token 30 days, revocable per device.
* Every protected operation re-checks ownership/participation in the service layer.
* Precise venue address and phone numbers are visible only to the client and to musicians with a confirmed booking.
* Rate limiting per IP and per account (DB-backed sliding window), uniform responses for sign-up/recovery to prevent
  account enumeration, progressive lockout on failed logins.
* Admin: separate cookie session, CSRF tokens, mandatory TOTP, role-based permissions, audit log.
* Android: tokens encrypted with an AES-GCM key held in Android Keystore; cleartext traffic disabled; backups exclude
  credentials.
