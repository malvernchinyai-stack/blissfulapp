<?php
declare(strict_types=1);

namespace Blissful\Payment;

interface PaymentProvider
{
    public function name(): string;

    /** 'sandbox' or 'live' */
    public function mode(): string;

    /**
     * Starts a hosted payment. Must not take card details: the payer completes payment on the provider's page.
     * @return array{redirect_url:string, poll_url:?string, provider_ref:?string}
     */
    public function initiate(array $payment, string $payerEmail, string $description): array;

    /**
     * Verifies and parses a webhook/result callback.
     * @return array{valid:bool, reference:?string, status:?string, provider_ref:?string, amount_minor:?int}
     *         status is normalised to one of: paid, failed, cancelled, pending
     */
    public function parseCallback(array $fields): array;

    /** Asks the provider for the current status (used by cron and on demand). Same shape as parseCallback. */
    public function poll(array $payment): ?array;
}
