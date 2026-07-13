# Profile App V1 Agent Guide

This guide documents `profileapp.v1` for future agents and developers. The app is a DAVVAG WebDock application that manages profiles and profile-linked commercial workflows such as invoices, receipts, deposits, purchase orders, GRNs, ledgers, and printable document views.

## App Identity

Runtime app code:

```text
profileapp.v1
```

Display title from `app.json`:

```text
Profile
```

Primary folder:

```text
davvag-core/localhost/apps/profileapp.v1
```

Startup component:

```text
frmprofile-list
```

Main service:

```text
services/profile
```

Service class:

```php
ProfileService
```

## Folder Map

```text
profileapp.v1/
  app.json
  Agent.md
  assets/
    appicon.png
  services/
    profile/
      component.json
      script.js
      service.php
  components/
    request-lock/
    frmprofile-list/
    frmprofile-list-popup/
    frmProfile/
    frmProfile1/
    frmprofile-view/
    frmprofile-change-status/
    frmInvoice/
    frmInvoice-view/
    frmInvoice-view-print/
    frmRecipt/
    frmRecipt-view/
    frmRecipt-view-print/
    frmDiposit/
    frmDiposit-view-print/
    frmPO/
    frmGRN/
    settings-app/
    schedules/
```

`frmProfile1`, `frmInvoice-view`, `frmRecipt-view`, `frmGRN`, `settings-app`, and `schedules` exist in the folder but are not currently registered as routable components in `app.json`. Treat them as legacy, popup-only, or incomplete until proven otherwise.

## Routes

`app.json` defines these route mappings:

```text
/             -> frmprofile-list
/view         -> frmprofile-view
/edit         -> frmProfile
/inv          -> frmInvoice
/dip          -> frmDiposit
/rpt          -> frmRecipt
/po           -> frmPO
/receipt      -> frmRecipt-view-print
/invoice      -> frmInvoice-view-print
/diposit      -> frmDiposit-view-print
/diposit_tr   -> frmDiposit-view-print
/deposit      -> frmDiposit-view-print
/deposit_tr   -> frmDiposit-view-print
/deposit_de   -> frmDiposit-view-print
/deposit_dv   -> frmDiposit-view-print
/change       -> frmprofile-change-status
```

Navigation mostly uses the DAVVAG shell route component:

```javascript
handler = exports.getShellComponent("soss-routes");
handler.appNavigate("../edit?id=" + id);
handler.appNavigate("../invoice?tid=" + invoiceNo);
```

Some components use absolute paths like `"/" + pagev`. Be careful: in DAVVAG route handling, absolute-looking paths can behave differently depending on the current shell route. Prefer sibling navigation such as `../edit?id=...` when moving within this app.

The admin dock route controller preserves `#/app/profileapp.v1` as the app root. Therefore `../edit?id=1` resolves correctly from both `#/app/profileapp.v1` and `#/app/profileapp.v1/list`, and `/edit?id=1` resolves from the Profile App root rather than from the dock root.

## Service Request Button Lock

`request-lock` is an always-loaded component declared in `app.json` after the `profile` service component.

It applies to every Profile App component and popup. When a button, submit input, or button-styled link fires a DAVVAG service request, the initiating control is locked until all associated requests complete. Repeated activation is rejected, and the lock is released after both success and error completion.

Do not remove `request-lock` from `configuration.webdock.onLoad`. New service-backed actions receive this protection automatically, but their backend services must still enforce idempotency when duplicate financial or destructive operations would be unsafe.

## Service API

Backend descriptor:

```text
services/profile/component.json
```

Backend implementation:

```text
services/profile/service.php
```

Registered methods:

```text
POST Save
POST DipositSave
GET  DepositCancelation?id=...
POST InvoiceSave
POST POSave
POST GRNSave
POST PaymentSave
GET  Search?q=...
GET  SearchV1?column=...&value=...
GET  ByID?id=...
GET  SupplierData
POST q
POST ChangeStatus
```

Important service dependencies:

```php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
```

`DipositSave`, `DepositCancelation`, and `PaymentSave` delegate to `Davvag_Order`. Before changing those flows, inspect:

```text
davvag-core/localhost/plugins/davvag-order/
```

or the tenant-local equivalent under `PLUGIN_PATH_LOCAL`.

## Components

### `frmprofile-list`

Profile search and launcher surface.

Key behavior:

- Uses `profile.services.SearchV1({column, value})`.
- Stores recently accessed profiles in `localStorage.tmpprofiles`.
- Loads launchers from `auth-handler.services.Launchers({appcode:"profileapp", component:"frmprofile-list"})`.
- Routes to edit/view/document components using `soss-routes`.

Known issues:

- Uses `profileapp` in launcher lookup, not `profileapp.v1`.
- Writes `localStorage.setItem("tmpprofiles", undefined)` in `clear`, which stores the literal string `"undefined"`.
- Contains console/debug code that mutates page metadata.
- Status class typo: `"pramary"` should be `"primary"`.

### `frmprofile-list-popup`

Popup version of profile search.

Key behavior:

- Calls `exports.Complete(p)` after profile selection.
- Used by deposit/company selection flows.

Keep this component compatible with popup callers.

### `frmProfile`

Main profile create/edit form.

Key behavior:

- Uses `exports.deferredVue` to render dynamic attributes through `dynamic-attributes`.
- Uses datepicker assets from `components/frmProfile`.
- Uses `davvag-tools/davvag-img-cropper`, `davvag-tools/capture`, and `davvag-tools/davvag-file-uploader`.
- Saves through `profile.services.Save(bindData.i_profile)`.
- Uploads profile image to uploader store:

```text
profile
```

Read URL pattern:

```text
components/dock/soss-uploader/service/get/profile/{profileId}
```

Known issues:

- `bindData` declares `submitErrors` twice.
- `clearProfile` assigns `showSearch=false` without `bindData`.
- `postSave` in the service checks `isset($profile->attribute)` but uses `$profile->attributes`, so new profile attribute insert may not run as intended.
- Existing profile update deletes and reinserts `profile_attributes`; confirm primary key behavior before changing.

### `frmprofile-view`

Profile detail page with ledger and service summary.

Key behavior:

- Loads profile via `Search({q:"id:"+id})`.
- Loads related data through `profile.services.q`:

```text
profilestatus by profileid
ledger by profileid
profileservices by profileId
```

- Opens attribute popup for service activation:

```javascript
attribute.open("attr_profile_service_activation", item, cb);
```

### `frmprofile-change-status`

Profile status update workflow.

Key behavior:

- Loads profile by id.
- Saves only status through `profile.services.ChangeStatus(profile)`.

Status values in UI/service are mixed case:

```text
active
inactive
void
ToBeActive
ToBeActivated
```

Normalize carefully if improving.

### `frmInvoice`

Invoice creation and invoice preview.

Key behavior:

- Loads store profile through `SupplierData`.
- Loads products using shell `soss-data`:

```text
products where showonstore:Y
```

- Saves through `profile.services.InvoiceSave`.
- On success routes to:

```text
../invoice?tid={invoiceNo}
```

Backend effects of `InvoiceSave`:

- Inserts `orderheader`.
- Inserts `orderdetails`.
- Creates `ledger` invoice entry.
- Updates `profilestatus`.
- Decrements `product_inventrymaster` for inventory items.
- Inserts `profileservices` for service items.

This is a high-risk money/inventory workflow.

### `frmInvoice-view-print`

Printable invoice view.

Key behavior:

- Loads `orderheader` and `orderdetails` by `tid`.
- Uses `menuhandler.services.qcrossdomain` in some paths for cross-domain data.

### `frmRecipt`

Receipt/payment collection workflow.

Key behavior:

- Loads open invoices:

```text
orderheader where profileId:{id},PaymentComplete:N
```

- Loads advance payments:

```text
payment_advance where profileId:{id},status:new
```

- Saves through `profile.services.PaymentSave`.
- On success routes to:

```text
../receipt?tid={receiptNo}
```

Backend effects are handled mostly by `Davvag_Order::SavePayment`.

Known issue:

- `calcTotals` adds to `AdvanceAmount` without resetting it to zero first. Repeated recalculation can overstate advance amount.

### `frmRecipt-view-print`

Printable receipt view.

Key behavior:

- Loads `paymentheader` and `paymentdetails` by `receiptNo`.

### `frmDiposit`

Deposit / advance payment workflow. The code uses the misspelling `Diposit` in routes, component names, and service methods. Do not rename casually; it is part of the existing API.

Key behavior:

- Loads products using:

```text
products where showonstore:N
```

- Can select a company profile through `frmprofile-list-popup`.
- Can check internal vault balance using `internal_profilestatus`.
- Saves through `profile.services.DipositSave`.
- On success routes to:

```text
../diposit?tid={TranNo}
```

Backend effects are handled mostly by `Davvag_Order::DipostSave`.

Known issue:

- `company_profileId:(bindData.company.id?bindData.company.id:0)` can fail when `bindData.company` is null.
- Uses `Diposit`, `Dipost`, and `deposit` spellings across the app.

### `frmDiposit-view-print`

Printable deposit view and cancellation surface.

Key behavior:

- Loads `dipositheader` and `dipositdetails` by `TranNo`.
- Can call `profile.services.DepositCancelation({id})`.

### `frmPO`

Purchase order workflow.

Key behavior:

- Saves through `profile.services.POSave`.
- Backend inserts `poheader` and `podetails`.
- Also contains GRN save logic in the same script via `profile.services.GRNSave(GRN)`.

### `frmGRN`

GRN component exists but is not registered in `app.json`.

Key behavior:

- Similar to PO/GRN workflow.
- Uses `GRNSave`.

Backend effects of `GRNSave`:

- Loads `poheader` by `tranNo`.
- Prevents duplicate GRN if PO `Complete == "Y"`.
- Inserts `grnheader` and `grndetails`.
- Marks PO complete.
- Updates inventory upward.
- Creates ledger entry with negative amount and `trantype = "GRN"`.

## Data Model

Schemas live under:

```text
davvag-core/localhost/schemas/
```

Core profile schemas:

```text
profile
profile_attributes
profilestatus
profiles_search_1
profileservices
```

Ledger schemas:

```text
ledger
internal_ledger
profilestatus
internal_profilestatus
```

Invoice schemas:

```text
orderheader
orderdetails
```

Receipt/payment schemas:

```text
paymentheader
paymentdetails
payment_advance
```

Deposit schemas:

```text
dipositheader
dipositdetails
```

Purchase order / GRN schemas:

```text
poheader
podetails
grnheader
grndetails
```

Inventory schema:

```text
product_inventrymaster
```

Important misspellings are schema/API-compatible names:

```text
catogory
campanyregno
Diposit
DipostSave
DipostCancel
Recipt
product_inventrymaster
```

Do not rename these without a migration plan.

## Backend Behavior Notes

### Ledger Direction

`updateLedger($ledgertran)` inserts into `ledger`, then updates/inserts `profilestatus`.

Current totals:

```text
invoice -> totalInvoicedAmount += amount
receipt -> totalPaidAmount += amount
grn     -> totalGRNAmount += amount
payment -> totalPaymentAmount += amount
```

`outstanding` always changes by `amount`. Receipt/payment flows must therefore pass correctly signed amounts.

### Inventory Direction

`updateInventry($value, $s)` only affects rows where:

```php
strtolower($value->invType) == "inventry"
```

Direction:

```text
s < 0 -> subtract qty
s > 0 -> add qty
```

Invoice calls `updateInventry($value, -1)`.
GRN calls `updateInventry($value, 1)`.

### Generic Query Endpoint

`postq($req)` accepts an array of objects:

```json
[
  {"storename":"ledger","search":"profileid:123"}
]
```

It directly queries each requested store and caches results. This is convenient but broad. Avoid exposing it to untrusted callers or adding UI that lets users supply arbitrary `storename`.

## External Dependencies

Shell components used throughout:

```text
soss-routes
soss-data
soss-uploader
soss-validator
app_popup
attribute_shell_popup
dynamic-attributes
auth-handler
```

App components used:

```text
davvag-tools / davvag-img-cropper
davvag-tools / capture
davvag-tools / davvag-file-uploader
profileapp / frmprofile-list-popup
```

Potential issue: some code refers to `profileapp`, not `profileapp.v1`. Verify current shell aliasing before changing.

## High Priority Improvements

1. Split `ProfileService` into smaller services.

Suggested boundaries:

```text
ProfileCrudService
ProfileLedgerService
ProfileInvoiceService
ProfileReceiptService
ProfileDepositService
ProfilePurchaseService
```

If DAVVAG app loading makes multiple services awkward, first split private helper methods inside `service.php`.

2. Add transaction-like rollback or compensation.

Invoice, GRN, payment, and deposit flows write multiple stores. Today a partial failure can leave header/detail/ledger/inventory out of sync.

3. Normalize validation.

Move repeated validation into service helpers:

```php
requireProfileId($id, $res)
requireContactFields($obj, $res)
requireLineItems($items, $res)
validateMoney($amount, $field, $res)
```

4. Fix money math.

Frontend currently mixes strings from `toFixed()` with numbers. Convert all totals through a shared numeric helper and avoid subtracting formatted strings.

5. Fix cache invalidation.

New profile creation does not consistently clear `profile`. Search caches can return stale results after save/status changes.

6. Replace `alert` and console debugging.

Use consistent `submitErrors`, `submitInfo`, or `$.notify`.

7. Make routes consistently relative.

Prefer:

```javascript
handler.appNavigate("../view?id=" + id);
```

over:

```javascript
handler.appNavigate("/" + pagev);
```

8. Make print views read-only and safe.

Document print views should not mutate records. Keep cancellation or status actions explicit and separated.

9. Add touch-friendly responsive UI.

Many forms are table-heavy and Bootstrap 3-era. Favor full-width form sections, larger buttons, and responsive grids.

10. Register or remove orphan components.

Decide what to do with:

```text
frmGRN
frmInvoice-view
frmRecipt-view
frmProfile1
settings-app
schedules
```

## Medium Priority Bug List

- `postSave` checks `$profile->attribute` but uses `$profile->attributes`.
- `postSave` updates profile before replacing attributes; if attribute insert fails, profile is still updated.
- `getByID` reads `$result->result[0]` for attributes without checking count.
- `getSearch` can use undefined `$search` when `q` is absent.
- `getSearchV1` can use undefined `$search` when required query params are absent.
- `postPOSave` sets errors for missing email/contact but does not return immediately.
- `postPaymentSave` catches `Throwable` and silently returns nothing.
- Several methods call `exit()` after setting errors; prefer returning `null`.
- `frmRecipt.calcTotals` does not reset `AdvanceAmount`.
- `frmDiposit.savePreview` can access `bindData.company.id` when `company` is null.
- `frmprofile-list.clear` stores invalid JSON in localStorage.
- Many scripts use undeclared globals such as `handler`, `routeData`, `sossdata`, `validator`, `valItem`, and `val`.
- `status()` returns typo `"pramary"`.
- `InvoiceItems.detailsString` is assigned from `InvoiceItem` singular, likely undefined.

## Suggested Modernization Sequence

1. Add a nonvisual service hardening pass.

Do this first:

```text
input validation
early returns
cache clearing
safe empty-result handling
numeric casting
no exit()
```

2. Add focused regression tests or manual scripts for:

```text
create profile
edit profile attributes
change status
create invoice
create receipt against invoice
create deposit
cancel deposit
create PO
create GRN
```

3. Improve UI component by component.

Recommended order:

```text
frmprofile-list
frmProfile
frmprofile-view
frmInvoice
frmRecipt
frmDiposit
frmPO / frmGRN
print views
```

4. Consolidate shared frontend helpers.

Candidates:

```text
routeData()
navigateRelative()
money(value)
formatDateTime(date)
loadProfile(id)
profileImageUrl(id)
notifyError(response, fallback)
```

5. Only then consider schema or naming migrations.

Misspelled field/store names are embedded in schemas, routes, code, and likely existing records. Do not rename without migration scripts and backward-compatible aliases.

## Validation Commands

From repository root:

```powershell
C:\xampp\php\php.exe -l davvag-core\localhost\apps\profileapp.v1\services\profile\service.php
node --check davvag-core\localhost\apps\profileapp.v1\components\frmprofile-list\script.js
node --check davvag-core\localhost\apps\profileapp.v1\components\frmProfile\script.js
node --check davvag-core\localhost\apps\profileapp.v1\components\frmprofile-view\script.js
node --check davvag-core\localhost\apps\profileapp.v1\components\frmInvoice\script.js
node --check davvag-core\localhost\apps\profileapp.v1\components\frmRecipt\script.js
node --check davvag-core\localhost\apps\profileapp.v1\components\frmDiposit\script.js
node --check davvag-core\localhost\apps\profileapp.v1\components\frmPO\script.js
node --check davvag-core\localhost\apps\profileapp.v1\components\frmGRN\script.js
Get-Content davvag-core\localhost\apps\profileapp.v1\app.json -Raw | ConvertFrom-Json | Out-Null
```

If you touch schemas used by this app:

```powershell
Get-Content davvag-core\localhost\schemas\profile.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\profile_attributes.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\profilestatus.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\ledger.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\orderheader.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\orderdetails.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\paymentheader.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\paymentdetails.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\dipositheader.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\dipositdetails.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\poheader.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\podetails.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\grnheader.json -Raw | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\schemas\grndetails.json -Raw | ConvertFrom-Json | Out-Null
```

## Manual Smoke Test Checklist

Use the DAVVAG shell route for this app and test:

```text
1. Open profile list.
2. Search by name, email, contact number, and ID.
3. Create a new profile with image upload.
4. Edit the same profile and confirm attributes persist.
5. View profile detail and confirm ledger/status panels load.
6. Change profile status and return to profile view.
7. Create invoice with one inventory item and one service item.
8. Confirm invoice print view loads.
9. Confirm inventory quantity changed for inventory item only.
10. Create receipt for the invoice.
11. Confirm receipt print view loads and invoice balance changes.
12. Create deposit and confirm deposit print view loads.
13. Cancel deposit from print view if the business flow allows it.
14. Create PO and GRN if those routes are enabled.
```

## Development Rules For This App

- Keep existing route names and schema misspellings unless doing a planned migration.
- Treat invoice, receipt, deposit, PO, GRN, inventory, and ledger changes as high risk.
- Prefer additive compatibility changes over renames.
- Do not remove `frmprofile-list-popup`; other flows depend on it for profile selection.
- Do not change uploader store name `profile` without updating all image URL references.
- Avoid adding more direct `SOSSData::Query` calls in components; prefer service methods.
- When improving UI, preserve the current `exports.Complete(...)` popup contract where present.
- Before large refactors, add a thin service facade and migrate one flow at a time.
