# Stelup Stripe Connect

This integration stays inside `stelup_shop` and reuses the existing DAVVAG profile, order, receipt and inventory APIs. It replaces the seller's legacy embedded API-key form and the `davvag-stripe` checkout redirect.

## Configure the platform

1. Sign in as `sysadmin` or `admin`, then open `#/app/stelup_shop/stripe-settings`. The profile Applications menu also exposes this link to administrators.
2. Enter the public HTTPS site URL, platform publishable/secret keys, Standard Connect OAuth client ID and connected-account webhook signing secret. Choose matching test or live credentials. Use test mode first. Blank secret fields preserve saved values; secret values are never returned by the API.
3. In Stripe Dashboard, enable Standard account OAuth and register the exact callback URI displayed on the page: `{site}/components/stelup_shop/stripe-api/service/Callback`.
4. Add a webhook for **events on connected accounts**, using `{site}/components/stelup_shop/stripe-api/service/Webhook`. Subscribe to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, and `checkout.session.expired`. Save that endpoint's `whsec_...` signing secret.
5. Ensure the tenant's framework permissions allow anonymous delivery **only to the signed Webhook operation**, signed-in sellers/buyers to their respective Stripe API operations, and administrators to Settings/SaveSettings. The current tenant group files are outside this task's application-only scope and were not changed. The service independently checks administrator/profile ownership and webhook signatures; do not bypass those checks. Verify delivery from Stripe's Dashboard before enabling live payments.
6. Enable Connect payments. Saving enabled settings verifies the secret key against Stripe's account endpoint and records the platform account ID.

PHP cURL with a working CA certificate store is required. TLS verification remains enabled. No credentials are included in source or test fixtures for a real account.

## Fees and settlement

- Seller commission is a percentage of the discounted product subtotal, deducted from the seller.
- Buyer service fee is a separate percentage of that subtotal, added to the buyer's charge and displayed as its own line in the cart estimate, final checkout, Stripe Checkout and confirmation. At 0%, it is omitted.
- Example: products 100.00, commission 10%, service fee 5%. Buyer pays 105.00; the platform application fee is 15.00; seller receives 90.00 before Stripe processing fees.
- Stripe direct charges are created on the connected Standard account. The platform receives commission plus service fee via `payment_intent_data.application_fee_amount`. Stripe processing fees are separate from these configurable percentages.
- Backend calculation uses database product prices, discounts, quantities, currency minor units and integer fee rounding. The browser's fee, price, account ID and commission values are never trusted. One checkout covers one seller and currency. Zero-decimal currencies and Stripe's ISK/UGX rules are handled; three-decimal currencies are explicitly rejected.

## Seller onboarding

Open `#/app/stelup_shop/selleronboard`. Sellers save shop details and policies, then connect or create their account on Stripe. They never enter Stripe passwords, bank details or secret keys in Stelup. OAuth state is session-bound, expires after 15 minutes and is single-use. Only the connected account ID and capability flags are stored; OAuth access/refresh tokens are not retained. Finishing registration requires both payments and payouts enabled, and capability status is checked again at checkout.

Sellers can return through Profile > Seller registration & payouts to refresh or reconnect their account. Standard OAuth cannot link accounts controlled by another platform; Stripe handles account eligibility and onboarding requirements.

## Payment lifecycle

The server issues a buyer/session-bound quote. Changes to products, quantities, fees or platform configuration require a refreshed quote. Checkout retries reuse the quote, pending invoice and Stripe idempotency key, including inventory already reserved for that invoice.

A signed, account/mode/amount/currency-verified paid Checkout Session produces the existing order receipt and acceptance. Replayed webhooks do not produce another receipt. The authenticated payment-return status endpoint can reconcile a paid Stripe session if the browser returns before its webhook. Browser redirects alone never mark an order paid or remove cart items.

The existing order system reserves inventory on pending invoice creation. A signed expired-session webhook cancels the unpaid order and releases that reservation once. Keep expiry delivery enabled. Check pending orders if delivery or database writes fail. Standard Stripe Dashboard refund/dispute handling remains separate; this change does not add an automated refund UI. Do not switch platform credentials/mode while that mode has unresolved checkouts.

## Private storage and deployment

`private/` contains platform settings, per-platform/test/live seller connections and immutable payment/fee snapshots. Runtime files are excluded from git, have a PHP exit guard, and the directory has an Apache access-deny file. Keep PHP execution enabled and deny this directory on any non-Apache server too. Preserve and back up this directory across deployments; losing it loses payment reconciliation references. No new database schemas are needed; the descriptor lists the existing order/receipt dependencies.

## Validation

Run:

```text
php tests/stripe_connect_test.php
php tests/stripe_receipt_test.php
php tests/static_regression_test.php
node tests/popup_regression_test.js
node tests/messaging_regression_test.js
```

The Stripe tests use temporary storage, fake credentials, mocked Stripe responses and mocked order data. They cover administrator access, CSRF, OAuth state/replay, fee arithmetic, database price validation, quote tampering, inventory reservations, duplicate checkout attempts, wrong account/amount, signature verification, duplicate webhooks, expired checkouts and buyer ownership. The receipt-adapter tests also verify reconciliation without a browser session and recovery after a partial database write. Browser fixture checks cover settings, onboarding, fee review and payment confirmation at 390/768/1440 pixels. Actual OAuth, test-mode payment, webhook delivery and receipt reconciliation require the configured Stripe platform and a running tenant; no real Stripe account was contacted during implementation.

References: [Stripe Standard OAuth](https://docs.stripe.com/connect/oauth-reference), [direct charges with Checkout](https://docs.stripe.com/connect/direct-charges?platform=web&ui=stripe-hosted), [webhook verification](https://docs.stripe.com/webhooks/signature), [currencies](https://docs.stripe.com/currencies).
