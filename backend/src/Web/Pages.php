<?php
declare(strict_types=1);

namespace Blissful\Web;

use Blissful\Config;

/** Static web pages for app.blissfulevents.co.zw (testing landing page, draft legal pages, sandbox checkout). */
final class Pages
{
    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function layout(string $title, string $body): string
    {
        $env = Config::env();
        $banner = $env === 'production' ? '' : '<div class="banner">Testing environment — not for real bookings or payments</div>';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::h($title) . ' · Blissful Gigs</title><style>'
            . ':root{--plum:#3b1f4a;--plum2:#5a2f6e;--gold:#e0a526;--ink:#1d1a20;--muted:#5f5866;--bg:#fbf8fc;--line:#e7e0ea}'
            . '*{box-sizing:border-box}body{margin:0;font:16px/1.6 system-ui,Segoe UI,Roboto,sans-serif;color:var(--ink);background:var(--bg)}'
            . '.banner{background:var(--gold);text-align:center;font-weight:700;padding:6px 12px;font-size:14px}'
            . 'header{background:linear-gradient(135deg,var(--plum),var(--plum2));color:#fff;padding:40px 16px}header .wrap{max-width:780px;margin:auto}'
            . 'header h1{margin:0 0 6px;font-size:34px;letter-spacing:-.5px}header p{margin:0;opacity:.9}'
            . 'main{max-width:780px;margin:auto;padding:24px 16px 60px}h2{margin-top:32px}a{color:var(--plum2)}'
            . '.btn{display:inline-block;background:var(--gold);color:var(--ink);font-weight:700;padding:12px 20px;border-radius:999px;text-decoration:none}'
            . '.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin:16px 0}.muted{color:var(--muted)}.small{font-size:14px}'
            . '.draft{border-left:4px solid var(--gold);background:#fff8e6;padding:12px 16px;border-radius:8px}ol li,ul li{margin:6px 0}'
            . 'footer{border-top:1px solid var(--line);padding:16px;text-align:center;font-size:14px;color:var(--muted)}'
            . 'button{font:inherit;padding:12px 18px;border-radius:999px;border:0;margin:6px;cursor:pointer;font-weight:700}.ok{background:#2e7d32;color:#fff}.no{background:#b3261e;color:#fff}.neutral{background:#ddd}'
            . '</style></head><body>' . $banner . $body
            . '<footer>Blissful Gigs by Blissful Studios · Harare · <a href="/privacy">Privacy (draft)</a> · <a href="/terms">Terms (draft)</a></footer></body></html>';
    }

    public static function landing(): string
    {
        return self::layout('Testing', '<header><div class="wrap"><h1>Blissful Gigs</h1><p>Book live musicians for services, weddings and events — by Blissful Studios.</p></div></header><main>'
            . '<div class="card"><h2 style="margin-top:0">Android test build</h2><p>This is a <strong>testing</strong> version for invited testers. It connects to a staging server with sample data and <strong>sandbox payments only — no real money is charged</strong>.</p>'
            . '<p><a class="btn" href="/download/app.apk">Download test APK</a></p><p class="muted small">You may be asked for the tester username and password Blissful Studios sent you.</p></div>'
            . '<h2>How to install</h2><ol><li>On your Android phone (Android 8.0 or newer), tap <em>Download test APK</em>.</li>'
            . '<li>When the download finishes, open it. If Android blocks it, tap <em>Settings</em> and allow installs from your browser for this one file.</li>'
            . '<li>Tap <em>Install</em>, then open <strong>Blissful Gigs (Testing)</strong>.</li>'
            . '<li>Sign up with your own email (you will receive a code), or use a sample account Blissful Studios gave you.</li>'
            . '<li>When you are done testing you can turn the "install unknown apps" permission off again.</li></ol>'
            . '<h2>What to try</h2><ul><li>As a <strong>client</strong>: post a request with one or more instrument positions and a budget, compare offers, and confirm one.</li>'
            . '<li>As a <strong>musician</strong>: set up your profile, browse matching requests and send an offer.</li>'
            . '<li>Chat inside a booking, try rescheduling and cancelling, mark it complete and leave a review.</li>'
            . '<li>Pay with the <strong>sandbox</strong> checkout and try both a successful and a failed payment.</li></ul>'
            . '<h2>Report a problem</h2><p>Use <em>Support</em> in the app menu, or email <a href="mailto:' . self::h((string) Config::get('SUPPORT_EMAIL', 'support@blissfulevents.co.zw')) . '">' . self::h((string) Config::get('SUPPORT_EMAIL', 'support@blissfulevents.co.zw')) . '</a>. Please include what you tapped and a screenshot.</p>'
            . '</main>');
    }

    public static function privacy(): string
    {
        return self::layout('Privacy policy (draft)', '<header><div class="wrap"><h1>Privacy policy</h1><p>Draft · last updated 28 October 2026</p></div></header><main>'
            . '<p class="draft"><strong>DRAFT FOR BUSINESS AND LEGAL REVIEW.</strong> This text was prepared to support testing. It is not legal advice and must be reviewed by a qualified Zimbabwean legal practitioner, including against the Cyber and Data Protection Act [Chapter 12:07] and any POTRAZ requirements, before public launch.</p>'
            . '<h2>Who we are</h2><p>Blissful Gigs is operated by Blissful Events &amp; Facilities ("Blissful Studios"), Harare, Zimbabwe. [Insert registered company name, address and data-protection contact.]</p>'
            . '<h2>What we collect</h2><ul><li><strong>Account:</strong> name, email, phone number, password (stored only as a one-way hash).</li>'
            . '<li><strong>Profiles:</strong> organisation type, city and area, biography, instruments, genres, experience, rates, photos, audio and video links you add.</li>'
            . '<li><strong>Bookings:</strong> event details, venue address, offers, agreed prices, messages between booked parties, reviews and dispute records.</li>'
            . '<li><strong>Verification (optional):</strong> identity documents you upload for staff review.</li>'
            . '<li><strong>Payments:</strong> payment references and status from our payment provider. We never receive or store card numbers or mobile-money PINs.</li>'
            . '<li><strong>Technical:</strong> device push token, IP address and security logs.</li></ul>'
            . '<h2>How we use it</h2><p>To run the marketplace (matching requests with musicians, creating bookings, messaging, payments, reminders), to keep the platform safe (fraud prevention, moderation, disputes), to meet legal and accounting obligations, and to improve the service.</p>'
            . '<h2>Who can see what</h2><ul><li>Public profile details (stage name, city/area, instruments, ratings, reviews, verification badges) are visible to signed-in users.</li>'
            . '<li>The exact venue address and phone numbers are shared only between a client and a musician with a confirmed booking.</li>'
            . '<li>Messages are visible only to the two booking participants, and to Blissful Studios staff if a message is reported or a dispute is opened.</li>'
            . '<li>Service providers we use: hosting, email, push notifications (Google Firebase) and payments (Paynow). [Confirm list before launch.]</li></ul>'
            . '<h2>Retention</h2><p>Verification documents are deleted automatically 7 days after review and no later than 90 days after upload. Booking, payment and review records are kept for [X years — confirm with accountant] for accounting and dispute purposes, in anonymised form after account deletion.</p>'
            . '<h2>Your choices and rights</h2><p>You can edit your profile, change notification settings, block users and delete your account in the app (Settings → Delete account). You may request access to or correction of your data by contacting [privacy contact].</p>'
            . '<h2>Security</h2><p>Connections are encrypted (HTTPS). Passwords are hashed, sign-in tokens are stored encrypted on your device, and staff access requires two-step verification and is logged.</p>'
            . '<h2>Children</h2><p>Accounts are for people aged 18 and over.</p></main>');
    }

    public static function terms(): string
    {
        return self::layout('Terms of service (draft)', '<header><div class="wrap"><h1>Terms of service</h1><p>Draft · last updated 28 October 2026</p></div></header><main>'
            . '<p class="draft"><strong>DRAFT FOR BUSINESS AND LEGAL REVIEW.</strong> These terms describe how the testing build behaves. Commercial terms (fees, cancellation, payouts, liability) are business decisions that must be confirmed and reviewed by a qualified legal practitioner before launch.</p>'
            . '<h2>1. The service</h2><p>Blissful Gigs connects clients who need live music with independent musicians. Blissful Studios is not the employer of musicians and does not perform the music services itself.</p>'
            . '<h2>2. Accounts</h2><p>You must be 18 or older, give accurate information, and keep your password secret. One person may use both client and musician roles on one account.</p>'
            . '<h2>3. Requests, offers and bookings</h2><p>A booking is formed only when a client accepts a specific version of a musician\'s offer. The agreed price, included services, extra charges and the cancellation policy in force at that moment are recorded with the booking. Changes to time require both parties to accept.</p>'
            . '<h2>4. Fees and payment</h2><p>Clients pay a service fee (currently 10% of the agreed price) shown before confirmation. Payments are collected by Blissful Studios through its payment provider and paid out to the musician after the dispute window closes. This is not an escrow or trust arrangement. [Confirm payout timing and method.]</p>'
            . '<h2>5. Cancellations</h2><p>Client cancellations: free more than 72 hours before the start, 50% of the total between 24 and 72 hours, 100% within 24 hours. If the musician cancels, the client is not charged and any payment is refunded. [Confirm policy and musician penalties.]</p>'
            . '<h2>6. Completion, disputes and reviews</h2><p>Either party may report a problem from the event start until 48 hours after completion. Blissful Studios staff will review evidence from both sides and decide on completion or refund. Only participants in completed bookings can leave one review each.</p>'
            . '<h2>7. Conduct</h2><p>No harassment, spam, discrimination, fraud or attempts to take bookings off-platform to avoid fees. We may hide content, suspend or close accounts that break these rules.</p>'
            . '<h2>8. Liability</h2><p>[To be drafted by legal counsel: limitation of liability, equipment damage, injury, force majeure, governing law (Zimbabwe).]</p>'
            . '<h2>9. Contact</h2><p>[Registered name, address, support email.]</p></main>');
    }

    public static function sandboxCheckout(string $ref, string $amount, string $status, string $sig): string
    {
        $form = $status === 'pending'
            ? '<form method="post" action="/sandbox/pay/' . self::h(rawurlencode($ref)) . '?sig=' . self::h($sig) . '">'
              . '<button class="ok" name="outcome" value="paid">Simulate successful payment</button>'
              . '<button class="no" name="outcome" value="failed">Simulate failed payment</button>'
              . '<button class="neutral" name="outcome" value="cancelled">Cancel</button></form>'
            : '<p>This payment is already <strong>' . self::h($status) . '</strong>.</p>';
        return self::layout('Sandbox checkout', '<header><div class="wrap"><h1>Sandbox checkout</h1><p>TEST PAYMENT — no money will move</p></div></header><main>'
            . '<div class="card"><p>Reference: <strong>' . self::h($ref) . '</strong></p><p>Amount: <strong>' . self::h($amount) . '</strong></p>' . $form . '</div>'
            . '<p class="muted small">In the live version this step happens on Paynow\'s secure page (EcoCash, OneMoney or card). Card details never reach Blissful Gigs.</p></main>');
    }

    public static function sandboxDone(string $outcome): string
    {
        $msg = match ($outcome) {
            'paid' => 'Test payment recorded as successful.',
            'cancelled' => 'Test payment cancelled.',
            default => 'Test payment recorded as failed.',
        };
        return self::layout('Sandbox result', '<main><div class="card"><h2 style="margin-top:0">' . self::h($msg) . '</h2><p>Return to the Blissful Gigs app. The booking will update within a few seconds.</p></div></main>');
    }

    public static function paymentReturn(): string
    {
        return self::layout('Payment', '<main><div class="card"><h2 style="margin-top:0">Thanks — return to the app</h2><p>Your payment status will appear on the booking once the payment provider confirms it. This can take up to a minute.</p></div></main>');
    }

    public static function notFound(string $msg = 'Page not found.'): string
    {
        return self::layout('Not found', '<main><div class="card"><h2 style="margin-top:0">' . self::h($msg) . '</h2><p><a href="/">Go to the testing home page</a></p></div></main>');
    }
}
