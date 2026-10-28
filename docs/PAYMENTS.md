# Payments

## Model (decided 28 Oct 2026 — needs business, accounting and legal sign-off)
- Launch market Zimbabwe, currency **USD**, amounts stored as integer cents.
- Client pays the agreed price + extras + a **10% service fee**, shown before confirming.
- Blissful Studios collects payment as merchant of record through Paynow, then pays the musician
  (price + extras) after the 48-hour dispute window. **This is not escrow** and must not be described as escrow.
- Payment status is tracked separately from booking status. A booking is confirmed when the offer is accepted; payment follows.

## What is implemented and tested
| Piece | State |
|---|---|
| Provider interface (`PaymentProvider`) | Implemented |
| **Sandbox provider** — signed fake checkout page + signed callbacks | Implemented, **tested** (success, failure, cancel, duplicate and forged callbacks, amount mismatch) |
| Idempotency: one pending attempt reused; `Idempotency-Key` replay; unique webhook event keys | Tested |
| Refunds on cancellation per agreed policy, at most one per booking | Tested (sandbox completes instantly) |
| Payouts queued after dispute window; marked paid by finance staff with a reference | Tested |
| **Paynow provider** (hosted checkout; EcoCash/OneMoney/cards happen on Paynow's page) | Implemented from Paynow's integration protocol; hash logic unit-tested; **never run against Paynow's servers** |
| Live mode | **Disabled**: refuses to start unless `PAYMENTS_LIVE_ENABLED=true` |

No card or mobile-money details ever reach Blissful Gigs.

## Activating Paynow (test mode first)
1. Blissful Events & Facilities registers a Paynow merchant account (business documents required by Paynow).
2. Paynow dashboard → Receive Payments → **create an integration** of type "3rd party shopping cart / link"; note the
   Integration ID and Integration Key. Set the result URL to `https://app.blissfulevents.co.zw/webhooks/paynow`.
3. On staging `.env`: `PAYMENT_PROVIDER=paynow`, `PAYNOW_INTEGRATION_ID=…`, `PAYNOW_INTEGRATION_KEY=…`, `PAYNOW_MODE=test`.
   In Paynow test mode only the merchant's own email can complete payments.
4. Run the manual checklist: pay a booking, cancel on Paynow's page, let one expire, replay a callback, check `/admin/payments`.
5. Only after written authorisation from Blissful Studios: production `.env` `PAYNOW_MODE=live` and `PAYMENTS_LIVE_ENABLED=true`.

## Things to confirm with Paynow/accountant before going live
- Refunds: Paynow does not offer this platform a refund API; finance staff refund through the merchant portal or mobile
  money and record it in `/admin/payments`. Confirm this process and timelines.
- Musician payouts are manual (EcoCash/bank) and recorded with a reference. Confirm tax/withholding obligations for paying independent musicians.
- Whether the service fee needs VAT treatment, and receipt/invoice requirements.
- Whether collecting on behalf of musicians requires any registration or agreement under Zimbabwean payment rules.
