# Security review

Self-review by the build agent, 28 Oct 2026. It is **not** an independent penetration test; one is recommended before public launch.

## Backend vs OWASP API Security Top 10 (2023)
| Risk | Controls | Evidence |
|---|---|---|
| API1 Broken object-level authorisation | Every service method re-checks ownership/participation; foreign ids return 404 | `SecurityTest::testCrossUserAccessIsDenied`, `testAcceptingSomeoneElsesOfferIsImpossible`, private file tests |
| API2 Broken authentication | Argon2id/bcrypt; 256-bit random tokens stored as SHA-256; 60-min access, rotating 30-day refresh with reuse detection; email verification; lockout + IP/account rate limits; uniform responses (no enumeration) | `AuthTest` (13 tests) |
| API3 Broken object-property authorisation | Explicit field whitelists; private fields (address, phone, coordinates) only to booked parties | `testMassAssignmentAndRoleEscalation`, `testAcceptCreatesBookingAndClosesPosition` |
| API4 Unrestricted resource consumption | Per-user/IP limits, per-action limits (requests, offers, chat, uploads, reports, support), upload size/type caps, pagination limits | `testIpRateLimitOnLogin`, `testChatSpamIsRateLimited` |
| API5 Broken function-level authorisation | Musician-only routes; admin uses a separate session + TOTP + roles | `testMusicianOnlyEndpointsRequireTheRole`, `AdminWebTest::testRolePermissions` |
| API6 Sensitive business flows | Atomic acceptance; version check for final terms; capacity and overlap checks under row locks; idempotency keys | `ConcurrencyTest` (real parallel processes) |
| API7 SSRF | Server never fetches user-supplied URLs; Paynow poll URL restricted to `https://www.paynow.co.zw/` | Code review |
| API8 Misconfiguration | HTTPS redirect, HSTS, nosniff, frame DENY, strict CSP on admin, dotfiles/.env blocked, no debug output outside development | `.htaccess`, `testErrorsDoNotLeakInternals` |
| API9 Inventory | Single versioned API surface documented in `Api.php`; staging/production separated by `.env` | — |
| API10 Unsafe consumption of APIs | Payment callbacks verified (HMAC / Paynow SHA-512), amount must match, Paynow "paid" confirmed by poll, duplicate events ignored | `PaymentTest` |

Other: PDO prepared statements only (`testSqlInjectionAttemptsAreInert`); output escaping in all HTML; uploads type-sniffed
from content, stored outside the web root with random names; verification documents private, retention-limited and every staff view audited;
logs redact passwords, tokens, codes, phone, email and addresses (`testLogsRedactSecrets`).

## Android vs OWASP MASVS v2
| Control | Implementation | Verified? |
|---|---|---|
| MASVS-STORAGE-1/2 | Tokens AES-256-GCM encrypted with a non-exportable Android Keystore key; excluded from backup/device transfer; no other sensitive data cached on disk | Code review only — **not yet run on a device** |
| MASVS-CRYPTO-1/2 | Platform crypto only; no custom algorithms; no keys in the APK | Code review |
| MASVS-AUTH-1/2/3 | Server-side auth for everything; refresh single-flight; sign-out revokes server session; 401 → sign-in | JVM tests with MockWebServer (written, **not yet executed** — needs CI) |
| MASVS-NETWORK-1 | HTTPS only; cleartext blocked in staging/release (debug-only exception for emulator host); user-installed CAs not trusted | Config review |
| MASVS-NETWORK-2 | Certificate pinning **not used** on purpose: shared-host Let's Encrypt certificates rotate every 60–90 days and a stale pin would lock users out. Revisit for production hosting. | Decision |
| MASVS-PLATFORM-1/2/3 | Only the launcher activity is exported; push service not exported; deep links carry ids only and are re-authorised server-side; external links opened only if `https://` | Manifest review |
| MASVS-CODE-1/2/4 | R8 minification in staging/release; minSdk 26; dependencies pinned in a version catalog; input validated server-side | Build config |
| MASVS-RESILIENCE | No root/tamper detection (not justified for this risk level) | Decision |
| MASVS-PRIVACY-1/2/3 | Minimal permissions (internet, network state, notifications); analytics disabled; account deletion in-app; privacy policy linked | Manifest/app review |

## Known gaps / recommended before public launch
- Independent penetration test of API and admin.
- Admin password policy and staff offboarding procedure.
- WAF / server-level rate limiting at the host (application limits exist).
- Malware scanning of uploads (only type and size validation today).
- Phone-number verification (no SMS provider) — phone is therefore never shown as "verified".
