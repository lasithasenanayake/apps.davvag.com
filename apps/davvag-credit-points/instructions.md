# DAVVAG Credit Points Application Development Prompt

## Role

Act as the senior DAVVAG Framework architect and implementation agent.

Design, implement, integrate, validate, and test a reusable DAVVAG application named:

```text
davvag-credit-points
```

The app must provide a secure virtual-credit wallet, immutable ledger, configurable credit packages, credit purchases, daily/weekly/monthly rewards, gift-credit coupons, balance checking, spending, reservations, reversals, reporting, and cross-application integration.

Do not stop after creating a plan. Inspect the repository and implement the working application using existing DAVVAG patterns.

---

# 1. Mandatory Context and Discovery

Before modifying files:

1. Read the complete DAVVAG Framework architecture context.
2. Read the relevant repository documentation:

   * `README.md`
   * `01-framework-overview.md`
   * `02-tenant-setup.md`
   * `03-application-development.md`
   * `04-components-and-services.md`
   * `05-database-schemas.md`
   * `06-workflows.md`
   * `07-plugins.md`
   * `08-auth-sessions-permissions.md`
   * `09-deployment.md`
   * `10-ai-agent-playbook.md`
   * `11-app-developer-guide.md`
   * `12-reusable-app-patterns.md`
3. Resolve the active tenant from `configloader.php`, root configuration, `RESOURCE_LOCATION`, and `LOCAL_DEV_HOST` or the active HTTP host.
4. Never assume `davvag-core/localhost` is the active tenant.
5. Search existing tenant apps, components, services, schemas, workflows, plugins, and payment integrations before creating anything.
6. Inspect existing apps related to:

   * payments
   * Stripe
   * DirectPay
   * IPG
   * banking
   * orders
   * products
   * scheduler
   * notifications
   * profiles
   * Lesson Manager
7. Reuse working DAVVAG payment, profile, transaction, notification, scheduler, popup, routing, and data capabilities.
8. Do not redesign DAVVAG as Laravel, Yii, or another framework.
9. Do not modify framework core for app-specific behaviour.
10. Preserve existing tenant registrations and unknown descriptor fields.

The uploaded DAVVAG architecture document is the final authority where repository patterns are unclear.

---

# 2. Application Objective

Create a platform-level credit-points capability comparable to virtual credits used by games and digital-content platforms.

Users must be able to:

* View their available credit balance.
* View reserved, pending, promotional, purchased, and expiring credits.
* Purchase configurable credit packages.
* Receive package bonus credits.
* Claim configured daily, weekly, or monthly free credits.
* Redeem gift-credit coupon codes.
* View credit transaction history.
* Use credits in other DAVVAG applications.
* Receive refunds or reversals through auditable transactions.

Administrators must be able to:

* Configure credit programs.
* Configure purchasable packages.
* Configure free-credit reward rules.
* Configure coupon campaigns.
* Generate secure coupon codes.
* Award credits manually.
* Reverse or correct transactions without editing ledger history.
* Search user wallets.
* View ledger activity.
* Reconcile balances.
* View purchase, reward, and coupon reports.
* Disable suspicious wallets, campaigns, packages, or codes.

Other DAVVAG applications must be able to:

* Check a profile’s balance.
* Check whether a profile can afford an action.
* Reserve credits.
* Capture a reservation.
* Release a reservation.
* Spend credits.
* Award credits through an authorized internal service.
* Reverse a previous credit transaction.
* Retrieve a transaction by reference or idempotency key.

---

# 3. Non-Negotiable Domain Rules

## 3.1 Credits are a ledger currency

Do not store credits in `profile_attributes`.

Do not make a user-editable profile field the authoritative wallet balance.

Treat credit points as a custom virtual currency represented by ledger accounts, transactions, and entries.

Use whole positive integers for credit amounts in the initial version. Do not use floating-point values.

## 3.2 Immutable ledger

Every balance-changing event must create a ledger transaction.

Examples include:

```text
PURCHASE
PACKAGE_BONUS
DAILY_REWARD
WEEKLY_REWARD
MONTHLY_REWARD
COUPON_GIFT
ADMIN_GRANT
ADMIN_DEBIT
SPEND
RESERVATION_CAPTURE
REFUND
REVERSAL
EXPIRATION
CHARGEBACK
TRANSFER
```

Once posted:

* Ledger transactions and balance-changing entries must be immutable.
* Do not delete or overwrite posted transactions.
* Corrections must create a separate reversing transaction.
* The reversal must reference the original transaction.
* Metadata that does not affect balances may be updated only when justified.

## 3.3 Balanced entries

Implement double-entry ledger behaviour.

Every ledger transaction must contain at least two entries.

For each credit program:

```text
total debits = total credits
```

Create system accounts for each credit program, such as:

```text
USER_WALLET
PURCHASE_ISSUANCE
REWARD_ISSUANCE
COUPON_ISSUANCE
ADMIN_ADJUSTMENT
SPEND_DESTINATION
REFUND_CLEARING
EXPIRATION_CLEARING
```

Hide debit/credit accounting details from normal users. User-facing balances must remain understandable as positive credit quantities.

## 3.4 Atomic writes

The following must succeed or fail together:

* Ledger transaction
* Ledger entries
* Credit lot updates
* Wallet balance projection
* Reward claim
* Coupon redemption
* Reservation update
* Purchase completion status

Use the existing DAVVAG `transactions` capability when available.

Do not perform sensitive check-then-update operations without a transaction, lock, conditional update, or equivalent concurrency control.

## 3.5 Idempotency

All non-read balance-changing operations must support idempotency.

Store:

```text
idempotency_key
operation_scope
request_hash
processing_status
result_transaction_id
safe_response
created_at
expires_at
```

The same idempotency key with the same request must return the original result.

The same key with different parameters must be rejected.

Apply idempotency to:

* Purchase creation
* Payment completion
* Payment callbacks
* Reward claims
* Coupon redemptions
* Credit grants
* Credit spending
* Reservations
* Reservation captures
* Reversals
* Refunds

Client-side button locking is required but must not be treated as backend duplicate protection.

## 3.6 No unauthorized negative balances

A wallet must not fall below zero unless the credit program explicitly permits negative balances.

The default must be:

```text
allow_negative_balance = false
```

Use balance/version locking to prevent simultaneous requests from double-spending the same credits.

## 3.7 Balance source of truth

The ledger is the authoritative balance source.

A wallet may maintain a transactional balance projection for fast balance checks:

```text
posted_balance
reserved_balance
available_balance
balance_version
```

Only the protected ledger service may update these fields.

Provide reconciliation functionality that recalculates wallet balances from posted ledger entries and reports any difference.

---

# 4. Credit Programs

Support at least one default global credit program.

Suggested default:

```text
code: CREDIT
name: Credits
symbol: C
precision: 0
timezone: tenant-configured timezone
allow_negative_balance: false
status: ACTIVE
```

Design the schemas so additional credit programs can be introduced later without redesigning the ledger.

A credit program must configure:

* Code
* Name
* Symbol
* Integer precision
* Tenant timezone
* Default spending order
* Negative-balance policy
* Purchase availability
* Reward availability
* Coupon availability
* Promotional-credit expiry policy
* Status

Default spending order:

1. Promotional credits expiring soonest
2. Other promotional credits
3. Purchased credits
4. Admin-issued non-expiring credits

Purchased credits must be non-expiring.

Reward, coupon, and promotional credits may have optional expiration dates.

---

# 5. Required Schemas

Use DAVVAG tenant schema JSON files and SOSSData logical namespaces.

Confirm naming conventions in the active tenant before finalizing names. A recommended baseline is below.

## 5.1 `davvag_credit_program`

Fields:

```text
id
code
name
description
symbol
precision
timezone
allow_negative_balance
spending_policy
purchased_credits_expire
default_reward_expiry_days
status
```

`purchased_credits_expire` must remain false unless the business model is changed after policy and legal review.

## 5.2 `davvag_credit_wallet`

Fields:

```text
id
program_id
owner_profile_id
wallet_type
posted_balance
reserved_balance
available_balance
balance_version
status
suspended_reason
```

Rules:

* One primary user wallet per profile and credit program.
* System accounts have no normal user owner.
* Wallet balances cannot be changed by generic CRUD endpoints.
* Use the existing profile facade for the active user.

## 5.3 `davvag_credit_transaction`

Fields:

```text
id
program_id
external_id
idempotency_key
transaction_type
status
source_app
reference_type
reference_id
original_transaction_id
description
metadata_json
effective_at
posted_at
actor_profile_id
actor_user_id
```

Statuses:

```text
PENDING
POSTED
FAILED
REVERSED
```

Do not change entries belonging to a posted transaction.

## 5.4 `davvag_credit_entry`

Fields:

```text
id
transaction_id
wallet_id
direction
amount
credit_lot_id
description
```

Direction:

```text
DEBIT
CREDIT
```

Amounts must always be positive integers.

## 5.5 `davvag_credit_lot`

Each grant creates one or more credit lots.

Fields:

```text
id
wallet_id
program_id
origin_transaction_id
source_type
original_amount
remaining_amount
expires_at
refundable
status
```

Source types:

```text
PURCHASE
PACKAGE_BONUS
REWARD
COUPON
ADMIN
REFUND
```

Credit lots are needed to support:

* Promotional-credit expiry
* Purchased-credit protection
* Refund eligibility
* Expiring-credit reporting
* Deterministic spending order

## 5.6 `davvag_credit_reservation`

Fields:

```text
id
wallet_id
program_id
amount
idempotency_key
source_app
reference_type
reference_id
status
expires_at
captured_transaction_id
released_at
```

Statuses:

```text
ACTIVE
CAPTURED
RELEASED
EXPIRED
```

## 5.7 `davvag_credit_package`

Fields:

```text
id
program_id
package_code
title
description
credit_amount
bonus_credit_amount
price_minor
currency
payment_channel
provider_product_id
product_id
purchase_limit_per_profile
first_purchase_only
active_from
active_until
sort_order
status
```

Store prices in the smallest currency unit as integers.

`product_id` is an optional mapping to the tenant `products.itemid` catalog record. It is distinct from `provider_product_id`, which remains the external payment provider's identifier. Administration must validate a mapped product server-side and allow the mapping to be cleared.

Never accept the package price or awarded credit amount from the browser as authoritative.

## 5.8 `davvag_credit_purchase_order`

Fields:

```text
id
order_reference
profile_id
wallet_id
package_id
package_code_snapshot
credit_amount_snapshot
bonus_amount_snapshot
price_minor_snapshot
currency_snapshot
payment_provider
provider_payment_reference
provider_status
order_status
idempotency_key
created_at
expires_at
paid_at
credited_at
credit_transaction_id
refund_status
```

Statuses:

```text
CREATED
PAYMENT_PENDING
PAID
CREDITED
FAILED
CANCELLED
EXPIRED
REFUND_PENDING
REFUNDED
PARTIALLY_REFUNDED
CHARGEBACK
```

Validate every state transition server-side.

## 5.9 `davvag_credit_payment_event`

Fields:

```text
id
provider
provider_event_id
event_type
payload_hash
signature_verified
order_id
processing_status
processed_at
error_message
```

`provider_event_id` must be unique.

Do not store payment secrets, card data, authorization headers, or unnecessary sensitive payload data.

## 5.10 `davvag_credit_reward_rule`

Fields:

```text
id
program_id
rule_code
title
cadence
award_mode
credit_amount
timezone
week_start_day
month_claim_day
claim_window_hours
eligibility_json
expiry_days
active_from
active_until
status
```

Cadence:

```text
DAILY
WEEKLY
MONTHLY
```

Award mode:

```text
CLAIM
AUTO
```

## 5.11 `davvag_credit_reward_claim`

Fields:

```text
id
rule_id
profile_id
period_key
claim_status
idempotency_key
transaction_id
claimed_at
```

Enforce a unique constraint equivalent to:

```text
rule_id + profile_id + period_key
```

## 5.12 `davvag_credit_coupon_campaign`

Fields:

```text
id
program_id
campaign_code
name
description
credit_amount
total_redemption_limit
per_profile_limit
first_time_only
minimum_account_age_days
eligible_group_ids_json
active_from
active_until
credit_expiry_days
status
```

Initial coupon type:

```text
GIFT_CREDITS
```

Design for future coupon types without implementing unnecessary functionality now.

## 5.13 `davvag_credit_coupon_code`

Fields:

```text
id
campaign_id
code_hash
masked_code
assigned_profile_id
maximum_redemptions
redemption_count
expires_at
status
```

Coupon codes are bearer secrets.

* Generate them with cryptographically secure randomness.
* Normalize user input consistently before hashing.
* Store a secure hash rather than plain reusable codes.
* Display the full code only during generation/export.
* Show only a masked version afterwards.

## 5.14 `davvag_credit_coupon_redemption`

Fields:

```text
id
campaign_id
coupon_code_id
profile_id
period_or_limit_key
idempotency_key
transaction_id
redeemed_at
```

Enforce campaign, code, and per-profile redemption limits atomically.

## 5.15 `davvag_credit_idempotency`

Create this schema only if an existing reusable DAVVAG idempotency mechanism is not already available.

Do not duplicate an existing framework capability.

---

# 6. Application Structure

Create the app under:

```text
{TENANT_RESOURCE_LOCATION}/apps/davvag-credit-points/
```

Recommended structure:

```text
davvag-credit-points/
├── app.json
├── app.php
├── assets/
│   └── appicon.svg
├── components/
│   ├── wallet-home/
│   ├── package-store/
│   ├── reward-centre/
│   ├── coupon-redeem/
│   ├── transaction-history/
│   ├── purchase-status/
│   ├── credit-balance-badge/
│   ├── admin-dashboard/
│   ├── admin-programs/
│   ├── admin-packages/
│   ├── admin-rewards/
│   ├── admin-coupons/
│   ├── admin-wallets/
│   └── admin-ledger/
├── services/
│   ├── credit-api/
│   ├── credit-ledger-api/
│   ├── credit-admin-api/
│   └── credit-payment-api/
└── lib/
    ├── CreditLedgerService.php
    ├── CreditRewardService.php
    ├── CreditCouponService.php
    └── CreditPaymentService.php
```

Additional internal classes are acceptable because ledger complexity justifies them, but all functionality must remain behind DAVVAG service-component contracts.

Do not introduce an unrelated MVC or repository architecture.

---

# 7. Application Descriptor

Create a valid `app.json`.

Use:

```text
app code: davvag-credit-points
title: Credit Points
icon: appicon.svg
```

Do not use `assets/appicon.svg` in the icon descriptor.

Declare:

* All components
* All service components
* Startup component
* Required `onLoad` services
* Routes
* Subapps where useful
* Schema dependencies
* App dependencies
* Workflow dependencies
* Plugin dependencies
* Required PHP extensions

Discover the exact installed dependency names before declaring them.

Expected capabilities may include:

```text
profile
auth
sossdata
transactions
phpcache
notify
davvag-flow
scheduler
existing payment app
```

Do not leave blank placeholder dependencies.

---

# 8. User-Facing Routes and Components

Provide routes similar to:

```text
#/app/davvag-credit-points/
#/app/davvag-credit-points/buy
#/app/davvag-credit-points/rewards
#/app/davvag-credit-points/redeem
#/app/davvag-credit-points/history
#/app/davvag-credit-points/purchase-status?id={orderId}
```

## Wallet home

Display:

* Available balance
* Reserved balance
* Promotional balance
* Purchased balance
* Credits expiring soon
* Next available reward
* Recent transactions
* Buy credits action
* Redeem coupon action

## Package store

Display active packages with:

* Package name
* Base credits
* Bonus credits
* Total credits
* Price
* Currency
* Package availability
* Purchase-limit messages

The server must recalculate all values during purchase creation.

## Reward centre

Display daily, weekly, and monthly rewards.

For each rule show:

* Award amount
* Eligibility
* Next claim time
* Claimed status
* Expiration information
* Claim button when available

## Coupon redemption

Provide:

* Code input
* Normalization
* Safe validation response
* Successful credit confirmation
* New balance
* Transaction reference

Do not reveal whether arbitrary guessed codes exist in a way that assists coupon enumeration.

## Transaction history

Support:

* Pagination
* Date range
* Transaction type
* Credit/debit display
* Status
* Reference
* Balance-after value when available

## Reusable balance component

Create a reusable component such as:

```text
credit-balance-badge
```

It must allow other apps to display the active profile’s balance without copying wallet logic.

---

# 9. Admin Components

## Dashboard

Display:

* Total outstanding user credits
* Purchased credits issued
* Reward credits issued
* Coupon credits issued
* Credits spent
* Credits expired
* Refunds and reversals
* Active wallets
* Payment success/failure totals
* Coupon redemption rate
* Reconciliation warnings

## Program manager

Allow authorized administrators to manage credit programs and policies.

## Package manager

Support:

* Create
* Edit
* Activate
* Deactivate
* Schedule
* Reorder
* Set base credits
* Set bonus credits
* Set price
* Set currency
* Set provider product references
* Set per-user purchase limits

Do not permit changes to historical purchase snapshots.

## Reward-rule manager

Support:

* Daily rules
* Weekly rules
* Monthly rules
* Manual claim
* Automatic award
* Eligibility
* Active periods
* Award amount
* Optional promotional expiry
* Preview of the next calculated period

## Coupon manager

Support:

* Campaign creation
* Secure code generation
* One shared code or many unique codes
* Assignment to a profile
* Total redemption limits
* Per-profile limits
* Active dates
* Export newly generated codes
* Disable codes or campaigns
* Redemption reporting

Never allow administrators to alter a completed coupon redemption.

## Wallet administration

Allow authorized administrators to:

* Search profiles using existing profile capabilities.
* View wallet balance and history.
* Grant credits with a mandatory reason.
* Debit credits with a mandatory reason.
* Suspend or reactivate a wallet.
* Reverse an eligible transaction.
* View purchased, reward, coupon, and expiring lots.

Manual adjustments must create ledger transactions and record the acting administrator.

## Ledger explorer

Support:

* Transaction lookup
* External-reference lookup
* Idempotency lookup
* Wallet filtering
* Transaction-type filtering
* Date filtering
* Entry details
* Reversal chain
* Related payment order
* Related reward claim
* Related coupon redemption

---

# 10. User Service Contract

Create a user-facing service component such as `credit-api`.

Recommended handlers:

```text
GET  Balance
GET  WalletSummary
GET  Transactions
GET  Packages
GET  PurchaseStatus
GET  RewardStatus
POST CreatePurchase
POST ClaimReward
POST RedeemCoupon
```

Every user service must derive the active profile using the existing profile facade.

Never accept an arbitrary `profile_id` from a normal user request to select another person’s wallet.

Example balance response:

```json
{
  "programCode": "CREDIT",
  "walletId": 123,
  "postedBalance": 1000,
  "reservedBalance": 100,
  "availableBalance": 900,
  "purchasedBalance": 700,
  "promotionalBalance": 200,
  "expiringSoon": 50,
  "balanceVersion": 21
}
```

Keep service result shapes stable and document them.

---

# 11. Protected Ledger Service Contract

Create a protected service component such as `credit-ledger-api`.

Recommended handlers:

```text
GET  Balance
GET  Transaction
POST Credit
POST Debit
POST Reserve
POST CaptureReservation
POST ReleaseReservation
POST Reverse
POST ReconcileWallet
```

## Debit request

Recommended input:

```json
{
  "programCode": "CREDIT",
  "amount": 100,
  "sourceApp": "course-manager",
  "referenceType": "lesson-access",
  "referenceId": "lesson-25-profile-99",
  "idempotencyKey": "course-manager:lesson-25:profile-99",
  "description": "Unlock lesson 25",
  "metadata": {}
}
```

Recommended result:

```json
{
  "transactionId": 501,
  "externalId": "credit_tx_...",
  "amount": 100,
  "status": "POSTED",
  "idempotentReplay": false,
  "balance": {
    "posted": 900,
    "reserved": 0,
    "available": 900,
    "version": 22
  }
}
```

## Protected writer rules

Only authorized services, workflows, payment handlers, and administrators may call balance-writing operations.

A browser request must not be able to award itself credits by directly calling `Credit`.

Validate:

* Calling app
* Acting user
* Required permission
* Wallet ownership
* Amount
* Program
* Reference
* Transaction state
* Idempotency
* Balance
* Spending limits

---

# 12. Reservation Behaviour

Use reservations when an external or cross-app action can fail after the balance check.

Reservation flow:

```text
CHECK AVAILABLE BALANCE
        ↓
CREATE RESERVATION
        ↓
REDUCE AVAILABLE BALANCE
        ↓
PERFORM BUSINESS ACTION
        ↓
CAPTURE OR RELEASE
```

Rules:

* Reservation creation must be atomic.
* Capturing creates the posted debit transaction.
* Releasing returns the reserved amount to available balance.
* Expired reservations must be released safely.
* Capture and release must be idempotent.
* A captured reservation cannot be released.
* A released or expired reservation cannot be captured.
* Every transition must be enforced server-side.

---

# 13. Credit Purchase Flow

Reuse an existing DAVVAG payment application or payment provider integration.

Do not collect or store raw card details inside `davvag-credit-points`.

Flow:

```text
USER SELECTS PACKAGE
        ↓
SERVER LOADS PACKAGE
        ↓
SERVER CREATES PURCHASE ORDER SNAPSHOT
        ↓
SERVER INITIATES PAYMENT
        ↓
PAYMENT PROVIDER PROCESSES PAYMENT
        ↓
SIGNED SERVER EVENT / VERIFIED CALLBACK
        ↓
ORDER MARKED PAID
        ↓
LEDGER CREDIT POSTED EXACTLY ONCE
        ↓
ORDER MARKED CREDITED
        ↓
USER SEES UPDATED BALANCE
```

Requirements:

* Derive price, currency, credits, and bonus credits from the server-side package.
* Create exactly one provider payment object per purchase order unless the existing provider requires another pattern.
* Use an idempotency key when initiating external payment.
* Include the internal order reference in safe provider metadata.
* Do not put sensitive personal or payment information in metadata.
* Never grant credits based only on a browser redirect or client-side success message.
* Process verified server-to-server payment events.
* Verify provider signatures using the existing provider integration.
* Store provider event IDs and reject duplicate processing.
* Handle events arriving out of order.
* Support payment reconciliation.
* Keep provider secrets in protected server-side configuration.
* Log safe payment lifecycle information.

Separate the following amounts in the ledger:

```text
PURCHASED_BASE_CREDITS
PACKAGE_BONUS_CREDITS
```

Purchased base credits are non-expiring.

Bonus credits may use the configured promotional expiry policy.

---

# 14. Refunds, Disputes, and Chargebacks

Refunds must not delete the original transaction.

Create a reversing or refund transaction linked to:

* Purchase order
* Original credit transaction
* Payment refund reference

Track which credit lots came from the purchase.

Default refund behaviour:

* A full automated refund is permitted when the purchased credit lot remains fully unused.
* A partial refund may reverse the proportional unspent purchased amount when the provider and business rules permit it.
* If credits have already been spent, do not silently create an unauthorized negative wallet.
* Route complex refund cases to an administrator unless the program explicitly permits negative balances.
* Package bonus reversal policy must be explicit.
* Chargebacks must be recorded and surfaced for review.
* Replayed refund or chargeback events must not duplicate reversals.

---

# 15. Daily, Weekly, and Monthly Rewards

Administrators must be able to configure multiple reward rules.

## Period calculation

Calculate reward periods server-side using the credit-program timezone.

Examples:

```text
Daily:   2026-07-28
Weekly:  2026-W31
Monthly: 2026-07
```

Do not trust a period key supplied by the browser.

## Claim mode

For `CLAIM` rules:

* The user actively claims the reward.
* Enforce one successful claim per profile, rule, and period.
* Reject replays.
* Return the existing result when the same idempotency key is retried.
* Calculate availability and period boundaries server-side.

## Automatic mode

For `AUTO` rules:

* Use the existing DAVVAG scheduler and workflow capabilities.
* Process eligible profiles in paginated batches.
* Store checkpoints.
* Make every profile award independently idempotent.
* Retry failed profiles without duplicating successful awards.
* Produce a run report.
* Avoid loading every profile into memory at once.

## Reward eligibility

Allow configurable eligibility such as:

* Authenticated active profile
* Account age
* User group
* Wallet status
* Previous purchase requirement
* First-time reward
* Tenant-defined eligibility metadata

Do not use client-provided eligibility fields as authority.

---

# 16. Coupon Gift Credits

Support coupon campaigns that award fixed credit amounts.

Redemption flow:

```text
NORMALIZE CODE
        ↓
HASH CODE
        ↓
LOOK UP ACTIVE CODE
        ↓
VALIDATE CAMPAIGN
        ↓
VALIDATE PROFILE ELIGIBILITY
        ↓
LOCK CODE / CAMPAIGN LIMIT
        ↓
CREATE REDEMPTION
        ↓
POST LEDGER CREDIT
        ↓
UPDATE REDEMPTION COUNT
        ↓
RETURN NEW BALANCE
```

All steps must be atomic.

Prevent:

* Repeated redemption
* Concurrent over-redemption
* Coupon enumeration
* Brute-force guessing
* Expired-code use
* Disabled-campaign use
* Changing the credit amount from the browser
* Replaying a successful request

Add rate limits and safe generic failure messages after repeated invalid attempts.

Log suspicious redemption patterns without exposing coupon values.

---

# 17. Credit Expiration

Purchased credits must not expire.

Reward, coupon, and promotional credits may expire only when configured.

Expiration must:

* Select eligible expired credit lots.
* Create an `EXPIRATION` ledger transaction.
* Reduce only the remaining amount of the expired lot.
* Never modify or delete the original grant.
* Be idempotent by lot and expiration date.
* Run through the scheduler in paginated batches.
* Generate an administrator report.
* Notify the user before expiration when the existing notification capability supports it.

---

# 18. Cross-App Integration

The app must be reusable by any DAVVAG application.

Do not duplicate wallet logic in consuming apps.

Provide documented examples for:

* Course or lesson unlock
* AI usage credits
* Premium content
* Marketplace actions
* Booking deposits
* Game actions
* API usage

Each consuming operation must provide:

```text
source_app
reference_type
reference_id
idempotency_key
amount
description
```

The combination of source and reference must be traceable from the ledger.

## Lesson Manager integration

The existing Lesson Manager has lesson pricing fields but lacks an authoritative credit wallet.

Integrate it only after the credit ledger is working.

Recommended one-time lesson unlock flow:

```text
LESSON MANAGER LOADS LESSON PRICE
        ↓
CHECK EXISTING UNLOCK
        ↓
RESERVE REQUIRED CREDITS
        ↓
CREATE LEARNER ACCESS / UNLOCK RECORD
        ↓
CAPTURE RESERVATION
        ↓
OPEN LESSON
```

On failure:

```text
RELEASE RESERVATION
```

Use an idempotency key similar to:

```text
lesson-access:{profileId}:{lessonId}
```

Do not charge the learner repeatedly each time the same permanently unlocked lesson opens.

Test the complete learner route, not only the credit service.

---

# 19. Security Requirements

Implement:

* Existing DAVVAG authentication
* Existing profile identity
* Server-side authorization for every sensitive service
* Least privilege
* Default-deny behaviour
* Ownership checks
* State-transition validation
* Rate limiting for coupon, reward, and purchase actions
* HTTPS assumptions in production
* Protected configuration
* Safe error responses
* Structured audit logs
* Pagination and query limits
* Input type, length, range, and enum validation
* Duplicate/replay protection
* Concurrency testing
* Safe raw queries with predefined schema queries and explicit aliases

Never:

* Trust client-provided balances
* Trust client-provided package prices
* Trust client-provided reward periods
* Trust a client payment-success flag
* Accept raw SQL from the browser
* Expose payment provider secrets
* Expose webhook secrets
* Store coupon codes in plain text without a documented protected requirement
* Allow users to select another profile’s wallet
* Retry financial POST failures blindly
* Expose stack traces publicly
* Disable view-object filtering simply to make data visible

For administrative grants, debits, reversals, and refunds:

* Require authorization
* Require a reason
* Show the significant transaction data before confirmation
* Lock the action button during processing
* Record the acting administrator
* Reject duplicate confirmation

---

# 20. Permissions and Visibility

Register the app in:

```text
tenant.json
```

Merge registrations into the appropriate group files without deleting existing entries.

Recommended visibility:

```text
web_user:
  wallet
  packages
  rewards
  coupons
  history

sysadmin:
  all user functions
  program configuration
  package management
  reward configuration
  coupon management
  wallet administration
  ledger and reconciliation
```

Do not expose administrator components through `anonymous.json`.

A payment callback endpoint may need public network access, but it must follow the existing payment integration pattern and require verified provider authentication rather than an ordinary public write permission.

---

# 21. Workflows and Scheduled Tasks

Use DAVVAG workflows only for multi-step orchestration and scheduled processes.

Potential workflows:

```text
davvag-credit-points/auto-reward
davvag-credit-points/expire-credit-lots
davvag-credit-points/release-expired-reservations
davvag-credit-points/reconcile-payments
davvag-credit-points/reconcile-wallet-balances
```

Before creating workflow JSON:

* Confirm whether the calling code uses the global or tenant-local `davvag-flow` engine.
* Author parameters for the actual workflow dialect.
* Preserve established source spellings.
* Define failure paths.
* Do not store secrets in workflow JSON.
* Declare all workflow dependencies.

Simple balance operations should remain service logic rather than unnecessarily complex workflows.

---

# 22. Frontend Requirements

Use DAVVAG Webdock and Vue component patterns.

Initialize Vue components through:

```javascript
exports.vue.onReady
```

Use declared service components rather than hard-coded service URLs.

For every service-triggering button:

* Set an in-flight reactive lock.
* Ignore repeated activation while locked.
* Release the lock on both success and failure.
* Keep it locked until the request actually settles.
* Do not use a fixed timer as completion detection.

Provide:

* Loading states
* Empty states
* Safe error messages
* Mobile-responsive layouts
* Accessible labels
* Clear credit formatting
* Purchase confirmation
* Reward countdowns
* Coupon success confirmation
* Transaction status indicators

Do not put authoritative balance or payment rules in frontend JavaScript.

---

# 23. Reporting and Reconciliation

Provide reports for:

* Wallet balances
* Outstanding credit liability
* Purchased credits
* Package bonuses
* Reward issuance
* Coupon issuance
* Spending by source app
* Expirations
* Refunds
* Reversals
* Chargebacks
* Payment failures
* Coupon usage
* Reward claims
* Suspended wallets
* Ledger imbalance warnings

Reconciliation must verify:

```text
sum of ledger entries = wallet posted balance
reserved records = wallet reserved balance
available = posted - reserved
debits = credits for every posted transaction
credited purchase orders have one ledger transaction
paid but uncredited orders are identified
credit transactions reference valid wallets and programs
reward claims reference one transaction
coupon redemptions reference one transaction
```

Never automatically rewrite ledger history to conceal a mismatch.

Report the mismatch and provide an authorized repair process that creates adjustment transactions where appropriate.

---

# 24. Mobile Distribution Boundary

Build the core ledger and web purchase flow independently from mobile-store billing.

Create a provider/channel abstraction so future clients can identify purchases from:

```text
WEB
APPLE_IAP
GOOGLE_PLAY
ADMIN
COUPON
REWARD
```

When credits are sold inside an App Store or Google Play distributed mobile app:

* Use the platform-approved billing channel.
* Validate purchase receipts or tokens server-side.
* Map store product IDs to server-side credit packages.
* Never trust the mobile client’s claimed product or quantity.
* Make receipt processing idempotent.
* Keep purchased credits non-expiring.
* Keep mobile billing logic outside the core ledger calculation.

Do not implement fake Apple or Google validation. Add those integrations only when the required credentials, packages, and existing application clients are available.

---

# 25. Required Tests

## Framework integration

Test:

```text
app descriptor
component descriptors
script resources
HTML resources
service descriptors
service routes
app visibility
supported docks
```

## Ledger tests

Test:

* Credit transaction
* Debit transaction
* Balanced entries
* Insufficient balance
* Exact-balance spend
* Reversal
* Duplicate idempotency key
* Same key with changed input
* Transaction rollback
* Balance projection
* Reconciliation
* Promotional lot spending order
* Purchased-credit non-expiration
* Promotional expiration

## Concurrency tests

Run simultaneous requests against one wallet.

Examples:

* Twenty debit requests against a wallet that can afford only five.
* Multiple claims for the same daily reward.
* Multiple redemptions of the final available coupon.
* Duplicate payment events.
* Simultaneous reservation and debit.
* Simultaneous reservation capture and release.

Verify that no duplicate credit or negative balance occurs.

## Purchase tests

Test:

* Valid package
* Disabled package
* Expired package
* Tampered client price
* Tampered credit amount
* Payment failure
* Payment success
* Repeated payment event
* Event arriving before redirect
* Event arriving after redirect
* Paid but temporarily uncredited recovery
* Refund
* Chargeback
* Unauthorized callback
* Invalid signature

## Reward tests

Test:

* Daily claim
* Weekly claim
* Monthly claim
* Duplicate claim
* Timezone boundary
* Ineligible user
* Disabled rule
* Expired rule
* Auto-award retry
* Promotional expiry

## Coupon tests

Test:

* Valid code
* Invalid code
* Expired code
* Disabled campaign
* Per-user limit
* Total limit
* Assigned profile
* Concurrent final redemption
* Replay attempt
* Brute-force rate limiting

## Authorization tests

Test:

* User reading own wallet
* User attempting another wallet
* User calling protected credit operation
* Non-admin package editing
* Non-admin manual grant
* Suspended wallet
* Missing active profile
* Invalid group visibility

Do not claim that a test passed unless it was executed.

---

# 26. Implementation Sequence

Follow this order:

```text
1. Resolve active tenant
2. Inspect reusable payment, profile, scheduler and transaction capabilities
3. Define final app and schema names
4. Design ledger invariants
5. Create program and system-wallet support
6. Create ledger transaction and entry services
7. Add wallet balance projection and reconciliation
8. Add reservations
9. Add user balance and history UI
10. Add package management
11. Integrate the existing payment capability
12. Add payment event idempotency
13. Add daily/weekly/monthly rewards
14. Add gift-credit coupons
15. Add admin wallet and ledger tools
16. Add scheduled expiration and reconciliation
17. Add reusable cross-app service contract
18. Integrate and test Lesson Manager
19. Register app and group visibility
20. Validate JSON, PHP, paths, namespaces and dependencies
21. Bump app and component versions
22. Run framework, security, concurrency and business-rule tests
```

---

# 27. Minimum Viable Release

The first usable release is complete only when it includes:

* One default credit program
* One wallet per profile
* Authoritative immutable ledger
* Fast balance check
* Transaction history
* Configurable credit packages
* At least one working existing DAVVAG payment provider
* Verified and idempotent payment completion
* Daily, weekly, and monthly reward rules
* Manual reward claiming
* Gift-credit coupon campaigns
* Coupon code generation and redemption
* Admin grant and reversal
* Protected internal debit service
* Reservation and capture/release services
* Reconciliation
* Lesson Manager integration test
* Correct group access
* Completed tests

Automatic mass rewards, advanced segmentation, multiple currencies, and native mobile billing adapters may be completed after the minimum release, but schemas must not block those extensions.

---

# 28. Acceptance Criteria

The application is accepted only when:

1. A newly authenticated profile receives or creates one authoritative wallet.
2. Balance cannot be edited through profile or generic CRUD interfaces.
3. Buying a package creates one order and credits the wallet exactly once.
4. Replayed callbacks do not duplicate credits.
5. Two simultaneous spends cannot overspend a wallet.
6. Daily, weekly, and monthly claims cannot be repeated during the same period.
7. Coupon redemption limits remain correct under concurrent requests.
8. Posted ledger history cannot be edited or deleted.
9. Reversals create new linked transactions.
10. Purchased credits do not expire.
11. Promotional credits expire only through an expiration transaction.
12. Other DAVVAG apps can check, reserve, spend, and release credits using stable services.
13. Lesson Manager can safely unlock a paid lesson without charging twice.
14. User and administrator permissions are separated.
15. All dependencies are declared.
16. App and component versions are bumped.
17. JSON and PHP syntax validation passes.
18. The app works in every supported Webdock.
19. The final report accurately states which tests were actually executed.
20. No payment secrets, coupon secrets, raw SQL, or authoritative business rules are exposed in frontend code.

---

# 29. Completion Report

After implementation, report:

```text
Active tenant resolved
Existing capabilities reused
Files created
Files modified
Schemas created
Schema relationships
Components created
Services created
Service methods
Workflows created
Payment provider integrated
Dependencies declared
Tenant registrations
Group visibility changes
Security controls
Idempotency controls
Concurrency controls
Routes tested
Service tests executed
Business-rule tests executed
Known limitations
Recommended next phase
```

Include exact file paths and test commands.

Do not report a feature or test as complete unless it was actually implemented or executed.
