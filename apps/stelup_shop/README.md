# Stelup Shop

Stelup Shop is a DAVVAG Framework marketplace application for seller onboarding, product catalog management, product discovery, carts, checkout, orders, trade proposals, profiles, follows, comments, and direct messages. It is a Webdock application built from Vue-style DAVVAG components and PHP service handlers backed by SOSSData schemas.

## Application structure

- `app.json` registers components, routes, startup services, plugins, schemas, and workflows.
- `apps/` contains routed UI templates and scripts. Legacy component CSS files remain unreferenced.
- `apps/stelup-theme/` ensures the existing CMS v7 stylesheets are available without duplicating them.
- `service/app-handler/` handles seller profiles, products, likes, favorites, comments, and trade proposals.
- `service/productsvr/` provides catalog lookup, product sharing metadata, and checkout.
- `service/p_svr/` provides public profiles, follows, seller products, and messaging.
- `service/seller_svr/` provides seller order lists, protected order details, and dispatch transitions.
- `service/login-handler/` provides authentication, profile data, policy, password, and payment-request operations.
- `tests/` contains non-destructive static regression checks.

## Main routes

The application starts at `home-sp`. Important routes include:

- `/xplo` - product listing and discovery
- `/selleronboard` - seller registration
- `/itemonboard` and `/group-sell` - product onboarding
- `/checkout-cart` - shopping cart
- `/checkout` - authenticated checkout
- `/profile` and `/userprofile` - seller/customer profiles
- `/messages` - messaging inbox
- `/selleradmin` - seller product administration
- `/orders` - seller order management
- `/order-complete` - completed-order confirmation

Routes are opened through the DAVVAG Webdock hash route, for example `#/app/stelup_shop/orders?t=pending`.

## Framework and dependencies

The app requires the DAVVAG Framework and the plugins declared in `app.json`, notably `auth`, `sossdata`, `phpcache`, `profile`, `profile-stelup`, `davvag-order`, `davvag-attributes`, and `notify`. It also depends on the `currency-configuration` and `davvag-tools` applications and the schemas listed in `app.json`.

The dispatch workflow uses both `orderheader_dispatched` and `orderdetails_dispatched`. Ensure both schemas are installed before dispatching orders.

## Configuration and setup

1. Install the DAVVAG Framework under the tenant resource location.
2. Install every application, plugin, schema, and workflow declared in `app.json`.
3. Configure the framework authentication provider and tenant database.
4. Configure Google/Facebook providers only if those login methods are enabled. Keep provider secrets outside source control.
5. Confirm the Webdock can load `stelup_shop` and its startup services.
6. Open `#/app/stelup_shop` and sign in before using checkout, seller, follow, comment, proposal, messaging, or order-management mutations.

No Laravel, Symfony, React, Node runtime, or package installation is required by this application.

## Theme integration

Stelup uses `assets/davvag-cms-v7/cms-v7.css` and `cms-v7-bootstrap-theme.css` directly. It inherits the CMS shell's live `--cms-*` variables, so theme previews and changes from `#/app/davvag-cms-v7-setting` affect its buttons, cards, forms, tables and modals. No Stelup component loads an app stylesheet or defines its own theme palette.

The `stelup-theme` startup component adds these shared assets only when the current dock has not already loaded them. Theme selection and persistence remain owned by CMS v7. Layout uses Bootstrap responsive columns and utilities, plus inline image sizing and spacing. Initially hidden form steps retain inline display states for the existing jQuery step navigation.

Validation: existing PHP static regression checks; all component/app JSON manifests; Vue template compilation; home/profile fixture renders at 390, 768 and 1440 pixels; live CSS-variable color changes. Authenticated checkout and messaging services require separate live-session verification.

## Security behavior

- Mutating likes, favorites, comments, follows, messages, products, proposals, checkout, policies, payments, and seller order transitions require an authenticated profile where applicable.
- Product ownership and seller order ownership are checked server-side.
- Checkout reloads product price, discount, seller, and stock from SOSSData; client cart prices are not authoritative.
- Product/profile text is rendered as text rather than raw HTML.
- Login and password reset accept POST bodies so passwords are not placed in query strings.

## Testing

From the application directory on the supplied XAMPP installation:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
C:\xampp\php\php.exe tests\static_regression_test.php
Get-ChildItem -Recurse -Filter *.js | Where-Object Name -ne marked.min.js | ForEach-Object { node --check $_.FullName }
```

Also validate all JSON manifests, then exercise login, product save, cart checkout, proposal actions, messaging, and seller dispatch against a non-production database.

## Troubleshooting

- **Component not loaded:** confirm its key, type, and location in `app.json`, then validate its `component.json`.
- **Schema errors:** install the missing schema from `dependencies.schemas`; dispatch requires the dispatched header and detail schemas.
- **Permission denied:** confirm the authenticated DAVVAG user has a linked profile and owns the product/order being changed.
- **Checkout rejected:** refresh the cart. A product may have moved seller, changed price, or run out of stock.
- **Stale UI data:** clear the relevant DAVVAG cache and reload; mutation services clear their primary caches after successful writes.

## Known limitations

The DAVVAG data layer used here does not expose an application-level transaction API, so multi-schema order and proposal transitions cannot be made fully atomic in this app alone. Payment-provider execution and production database workflows must be integration-tested in the configured tenant environment. The legacy social-login callbacks require provider-specific review before production use.

## Popup integration

Catalog views open product, group, message and comment dialogs through `shop-popup`, which delegates to the shell's `app_popup.open(...)`. Popup children return actions through `exports.Complete(...)`, as documented in `docs/11-app-developer-guide.md` (Reusable Lookup Popups). The downloader's `completedEvent` value is a callback collection and must not be called directly.

The coordinator closes the current instance before opening another dialog or navigating. Buy returns one action so its cart update and redirect run once. Closing also destroys the mounted Vue instance. Product dialogs initialize and normalize `groupItems`, and show loading/error states while the product request runs.

Run `node tests/popup_regression_test.js` for callback and product-state regression checks. Browser integration was also checked against the actual CMS popup shell and downloader using fixture services; live service verification remains separate.

CMS preloading registers helper components without calling their `onLoad()`. Catalog popup callers initialize `shop-popup` once, when its `open` export is first needed. The popup regression test covers this preloaded-but-uninitialized state.

## Messaging layout

The messages route uses a shared-theme inbox and chat panel. Mobile shows either the inbox or the selected conversation, with Back returning to the list. The scrollable message history keeps the composer visible. Search, loading/retry states, safe message decoding and send status work in the inbox and product-message popup.

The inbox disposes the previous conversation and invalidates old downloader requests when switching or returning. Message callbacks ignore disposed views; unsuccessful sends preserve the draft. Run `node tests/messaging_regression_test.js` for these behaviors. No service or schema changes are required.

## Stripe Connect payments

Configure `#/app/stelup_shop/stripe-settings` as an administrator. Sellers connect their Standard Stripe accounts through the redesigned seller registration. Commission is deducted from the seller subtotal; a configured buyer service fee is shown separately before payment. Keys remain server-side. See [STRIPE_CONNECT.md](STRIPE_CONNECT.md) for required Stripe Dashboard/tenant-permission setup, fee examples, storage and test instructions.
