# Stelup Shop Improvements

## Assessment

The application is a legacy DAVVAG Webdock marketplace with 23 routed UI components and five PHP service handlers. Its core workflows are present, but the audit found unsafe trust in browser data, missing ownership checks, read endpoints that wrote placeholder records, obsolete routes/assets, raw HTML rendering, and several concrete runtime defects. The original app contained no README or automated test directory.

## Confirmed bugs and fixes

- Fixed seller `cancel` incorrectly calling `DispatchOrder`; unsupported transitions are now rejected.
- Fixed seller service methods referencing an undefined `$res` because `getUpdateOrder` accepted no response argument.
- Added seller ownership checks to order detail and dispatch operations.
- Avoided the framework order plugin's incorrect dispatched-detail insert by performing the app transition against `orderdetails_dispatched` and declaring that schema dependency.
- Removed dummy review/follow/like/favorite inserts from product read endpoints.
- Fixed an undefined `$result` error branch in the follow service.
- Added defaults for optional query parameters to avoid undefined-index warnings.
- Fixed checkout exception reporting (`$req->SetError` to `$res->SetError`).
- Fixed login state failing to return the recovered session.
- Fixed profile attribute creation checking `attribute` while using `attributes`, and guarded optional attributes during updates.
- Fixed profile history loading rejected orders twice; dispatched orders now populate `order_dispatched`.
- Fixed pending orders defaulting to unsupported type `new`.
- Removed buttons bound to nonexistent `marksold` handlers.
- Fixed the cart watcher being incorrectly nested under `computed` and calling misspelled `consol.log`.
- Replaced obsolete `davvag-shop` links, share endpoints, checkout callbacks, and a missing cart icon path with `stelup_shop` routes/assets.
- Replaced redirect to the nonexistent `order-error` route with an inline checkout error.

## Security improvements

- Checkout now authenticates the buyer, reloads authoritative product price/discount/stock/seller data, validates quantities, and rejects mixed-seller carts.
- Product creation/update requires authentication and enforces seller ownership.
- Seller order details and dispatch require ownership.
- Like, favorite, comment, follow, message, proposal, payment-request, and profile-policy mutations bind identity to the authenticated profile instead of trusting browser-supplied IDs.
- Product sharing metadata is HTML-escaped, uses the current HTTP scheme, and uses a safely encoded redirect.
- Raw `v-html` and unsanitized Markdown rendering were removed from shop templates.
- Login and password reset changed from query-string GET parameters to POST request bodies.

## UI and theme improvements

- Added a reusable `stelup-theme` startup component based on actual CMS V7 tokens and Bootstrap compatibility patterns.
- Standardized cards, panels, modals, buttons, fields, tables, badges, dropdowns, focus states, shadows, and spacing.
- Added mobile modal/card/button behavior, responsive gutters, image constraints, and reduced-motion support.
- Added accessible labels to floating cart buttons.

## Files changed

- `app.json` - theme registration, startup loading, dispatched-detail schema.
- `apps/stelup-theme/*` - shared theme component and CSS.
- `service/app-handler/service.php` - authenticated mutations, ownership checks, defensive input handling, removal of read-side writes.
- `service/productsvr/service.php` - authoritative checkout validation and escaped share metadata.
- `service/p_svr/service.php` - authenticated follows/messages and safer query defaults.
- `service/seller_svr/service.php` - protected order reads and corrected dispatch transition.
- `service/login-handler/service.php` and `component.json` - session/profile/policy/payment fixes and POST password reset.
- Shop templates/scripts - XSS removal, route/asset repairs, order UI fixes, and Vue watcher repair.
- `README.md`, `IMPROVEMENTS.md`, `tests/static_regression_test.php` - operational and verification documentation.

## Verification performed

- PHP 8.2.12 syntax lint: passed for all five service files.
- Node 24.21 JavaScript syntax checks: passed for all non-vendored JavaScript files.
- JSON parsing: passed for `app.json`, every component manifest, and service configuration.
- Static regression test: see `tests/static_regression_test.php`.
- Residual scan for raw `v-html`, obsolete `davvag-shop` routes, misspelled `consol.log`, wrong request error handling, and placeholder database inserts.
- Local HTTP smoke checks: DAVVAG entry point, shop icon, theme CSS, theme script, and an existing component stylesheet returned HTTP 200.
- Browser automation reached the local server, but the visual workflow run was stopped after concurrent user input was detected in Chrome; no form or business-data mutation was attempted.

## Remaining limitations and follow-up

- Live database, authentication-provider, payment-provider, and browser workflow tests require the configured tenant services and safe non-production data.
- Multi-table operations remain non-atomic because no app-level SOSSData transaction contract was found.
- The shared `davvag-order` plugin contains broader legacy behavior outside this app's authorized change scope and should receive a separate compatibility/security audit.
- Social-login callbacks and legacy cookie/session settings should be reviewed with their deployed provider configuration.
- Apache logged repeated local Xdebug client timeout notices and an existing CMS V7 Font Awesome `woff2` 404; neither originates in Stelup Shop, but both are worth cleaning up in the local environment.
