# Delivery Production Audit

Audit date: 2026-08-15
Scope: the complete tracked Laravel application, including checkout, cart/pricing, coupons, order persistence, public tracking, kitchen/courier flows, Filament, uploads, routes, migrations, configuration, deployment documentation, and the existing automated tests.

## Executive summary

### Production readiness

The application has a strong baseline: order and item writes are transactional, cart prices are recalculated from catalogue data, coupon usage is claimed atomically, money calculations use integer cents, tracking URLs use unique random tokens, kitchen/admin/driver routes are authenticated as intended, and status transitions use database locking.

It is nevertheless **not ready for an unqualified production deployment** because three confirmed high-impact order-flow defects remain:

1. unfinished orders from the previous calendar day disappear from the kitchen board;
2. concurrent/replayed checkout requests are not actually idempotent and can create duplicate orders;
3. an exception after the database commit is reported to the customer as a failed order even though the order already exists.

Counts:

- CRITICAL: **0**
- HIGH: **3 confirmed**
- MEDIUM: **4 confirmed**
- LOW: **0 reported** (cosmetic/theoretical items were intentionally excluded)
- IGNORE: formatting-only Pint findings, documented below

### Deployment blocker

Yes. The three HIGH findings can respectively hide an accepted order from the kitchen, create duplicate orders, or tell a customer that a committed order failed. They should be fixed and regression-tested before real order traffic is accepted.

## Confirmed Critical / High issues

### HIGH-01 — Unfinished orders disappear from the kitchen board at midnight

- **Severity:** HIGH
- **Status:** CONFIRMED BUG
- **File:** `app/Livewire/OrderBoard.php`
- **Class / method:** `OrderBoard::render()`
- **Area:** lines 68-79, specifically `whereDate('created_at', today())` at line 75
- **What is wrong:** Every active board column (`nea`, `preparing`, `ready`, `out`) is restricted to orders created on the current calendar day. An order placed shortly before midnight and not completed before the date changes is still active in the database but is removed from the kitchen UI on the next poll.
- **Reproduction:** Create an order with `created_at`/`placed_at` yesterday and status `nea` or `preparing`; authenticate as kitchen staff and render `/kitchen`. The order is absent even though it is neither completed nor cancelled. A real case is an order submitted at 23:59 and still being prepared at 00:00.
- **Production impact:** The kitchen can miss an accepted order completely. `KitchenHistory` is also scoped to today, so the order is not rescued by the normal kitchen history view. It remains discoverable only through the admin order list. This can directly cause a lost/unfulfilled order.
- **Minimal fix:** Remove the date restriction from active board queries and query by active status only. If old open orders need visual distinction, show their date; do not hide them while they remain non-terminal. Add a regression test with an open previous-day order.

### HIGH-02 — Checkout protection does not prevent concurrent duplicate orders

- **Severity:** HIGH
- **Status:** CONFIRMED RACE CONDITION
- **Files:** `app/Livewire/CheckoutPage.php`, `app/Actions/CreateOrder.php`, `config/session.php`
- **Class / method:** `CheckoutPage::submit()`, `CreateOrder::execute()`
- **Area:** `CheckoutPage.php` lines 51-57 and 97-135; `CreateOrder.php` lines 24-88; `config/session.php` (no global session blocking configuration)
- **What is wrong:** `confirmedOrderId` prevents only a later action made from a Livewire snapshot that has already received the first successful response. Two requests carrying the same pre-submit snapshot both have `confirmedOrderId === null`. Both can read the same server-side cart before either request clears it and both can insert an order. The rate-limit counter is incremented only after successful creation, so it does not serialize the requests. There is no order idempotency key or unique database constraint. Laravel session requests are not blocked by default, and the Livewire update route is not session-blocked.
- **Reproduction:** From the same session, send two concurrent `livewire/update` submit requests using the same valid pre-submit component snapshot (or submit from two tabs before either response completes). Both requests can enter `CreateOrder::execute()` with the same cart and commit separate orders. The existing “double submit” tests call `submit` sequentially on an already-updated test component, so they do not exercise this race.
- **Production impact:** Duplicate kitchen tickets, duplicate coupon claims, and—once online payments are enabled—potential duplicate payment attempts or customer confusion. A mobile retry/replay is exactly the case the comments claim to handle but the database cannot currently identify as the same checkout.
- **Minimal fix:** Generate a checkout idempotency key before submission, persist it on the order under a unique database constraint, and return the existing order when the same key is retried. A per-session lock spanning cart read, commit, and cart clear can be an additional guard, but a unique database-backed key is the reliable final barrier. Add a genuinely concurrent/replayed-request test rather than another sequential component call.

### HIGH-03 — Post-commit exceptions produce a false “order failed” response

- **Severity:** HIGH
- **Status:** CONFIRMED BUG
- **Files:** `app/Actions/CreateOrder.php`, `app/Livewire/CheckoutPage.php`
- **Class / method:** `CreateOrder::execute()`, `CheckoutPage::submit()`
- **Area:** transaction ends at `CreateOrder.php` line 84; fallible post-commit work is at lines 88-98; generic failure handling is at `CheckoutPage.php` lines 109-120
- **What is wrong:** The order transaction is already committed before the cart clear and the `order.created` log. The log payload performs a fresh database query through `$order->items()->count()`. If that query or the log handler throws, `CreateOrder::execute()` throws after commit. `CheckoutPage::submit()` catches it as if persistence failed and displays “Δεν ήταν δυνατή η καταχώριση”, even though the order and items already exist. Because the cart is cleared before the logging query, the accompanying claim that the cart was retained is also false on this path.
- **Reproduction:** Force the `order.created` log handler to throw (for example an unwritable log destination) or make the post-commit `items()->count()` query fail. Submit a valid checkout. The database contains the committed order, while the Livewire component shows the failure message; the cart has already been forgotten.
- **Production impact:** The customer may rebuild and submit the order again, creating a duplicate, while the first order is already visible to the kitchen. Operational failures such as a full log disk or a transient connection failure therefore change the customer-visible truth of an already committed purchase.
- **Minimal fix:** Do not allow observability/post-commit bookkeeping to determine whether an order is reported as accepted. Use the already-known cart line count instead of a new query, make logging best-effort, and return the committed order once the transaction succeeds. Add a test where logging fails after commit and assert that the customer still receives the existing order confirmation.

## Medium issues

### MEDIUM-01 — Submit-time catalogue verification ignores an inactive category

- **Severity:** MEDIUM
- **Status:** CONFIRMED BUG
- **File:** `app/Actions/CreateOrder.php`
- **Class / method:** `CreateOrder::verifiedLines()`
- **Area:** lines 152-180, especially the availability check at lines 167-174
- **What is wrong:** A product can enter the cart only when its category is active, but checkout revalidation checks only that the product exists and `is_available` is true. It does not re-check the category’s `is_active` state.
- **Reproduction:** Add a product from an active category to a cart, deactivate the category in Filament, then submit the existing cart. The order is accepted even though the product/category has disappeared from the menu.
- **Production impact:** A category disabled because it is unavailable can continue receiving orders from carts held in existing sessions for up to the session lifetime.
- **Minimal fix:** Load/check the category in `verifiedLines()` (or constrain the product query with the same active-category predicate used by `MenuPage::menuProduct()`) and correct/refuse the stale cart before creating the order.

### MEDIUM-02 — Deleted, detached, or no-longer-valid product options remain orderable

- **Severity:** MEDIUM
- **Status:** CONFIRMED BUG
- **File:** `app/Actions/CreateOrder.php`
- **Class / method:** `CreateOrder::verifiedLines()`, `CreateOrder::currentOptions()`
- **Area:** lines 157-180 and 218-249
- **What is wrong:** Option values are resolved globally by ID, without proving that each value’s group is still attached to the line’s product. If an option value is deleted, `currentOptions()` deliberately falls back to the stale session snapshot at lines 241-244, including its old price delta. Detaching a group from a product also has no effect because the global option row still resolves. Current required/min/max selection rules are not revalidated at submit.
- **Reproduction:** Add a product with an option, then either delete that option value or detach its group from the product before checkout. Submit the stale cart. A deleted value is retained from its snapshot; a detached value is resolved globally and accepted. If its price changed only through deletion/replacement, the old amount can be charged.
- **Production impact:** The kitchen can receive a customization that the owner removed, and the customer can be charged an obsolete or invalid combination. This is a correctness issue rather than a demonstrated browser-tampering exploit because the production cart is stored server-side.
- **Minimal fix:** At checkout, validate option IDs through the current option groups attached to that specific product, re-apply selection constraints, and treat a missing ID as a catalogue change rather than as a legacy snapshot when an `option_value_id` is present. Preserve fallback behavior only for genuinely legacy entries with no stored ID.

### MEDIUM-03 — Checkout accepts addresses that the MySQL schema cannot store

- **Severity:** MEDIUM
- **Status:** CONFIRMED BUG
- **Files:** `app/Livewire/CheckoutPage.php`, `database/migrations/2026_06_26_223802_create_orders_table.php`
- **Class / method:** `CheckoutPage::submit()` validation; orders schema
- **Area:** validation line 68 (`max:500`); migration line 18 (`string('address')`, i.e. VARCHAR(255))
- **What is wrong:** Addresses of 256-500 characters pass application validation but exceed the production MySQL column. MySQL strict mode is enabled in `config/database.php`, so the insert fails instead of truncating.
- **Reproduction:** On MySQL/MariaDB in strict mode, submit a valid checkout with a 256-character address. Validation passes, `Order::create()` raises a data-too-long database exception, and the customer receives the generic retry message. SQLite’s unconstrained text storage is why the current test suite does not reveal it.
- **Production impact:** A legitimate customer can be unable to place an order and will be told to retry indefinitely.
- **Minimal fix:** Align validation and schema. The smallest change is `max:255`; alternatively widen the database column only if 500 characters are a real requirement. Add a boundary test that reflects the chosen production constraint.

### MEDIUM-04 — Negative final product prices are accepted and persisted

- **Severity:** MEDIUM
- **Status:** CONFIRMED BUG
- **Files:** `app/Filament/Resources/ProductResource.php`, `app/Filament/Resources/OptionGroupResource/RelationManagers/OptionValuesRelationManager.php`, `app/Services/PricingService.php`, `database/migrations/2026_06_26_223802_create_products_table.php`, `database/migrations/2026_06_26_223803_create_option_values_table.php`
- **Class / method:** Filament price fields; `PricingService::lineTotal()`
- **Area:** product field lines 81-85; option delta field lines 23-27; pricing lines 15-24; signed decimal columns in the migrations
- **What is wrong:** The admin product base-price input is numeric but has no minimum, option deltas can reduce a unit below zero, and `lineTotal()` does not reject/clamp a negative final unit amount. The database decimals are signed. Coupon totals clamp discounts, but the underlying negative item/subtotal does not.
- **Reproduction:** Create an available product with a negative base price in Filament, or configure an option delta whose magnitude exceeds the base price. Add it to the public cart and submit. The negative line/subtotal/total is calculated and stored.
- **Production impact:** A catalogue entry mistake can produce nonsensical totals and an invalid amount for the courier or a future online payment request.
- **Minimal fix:** Add a non-negative minimum to base prices and enforce server-side that every final unit price is at least zero. If negative option deltas are intentionally supported, validate the resulting unit price rather than banning all negative deltas.

## False positives / important areas checked and found correct

- **Order atomicity:** `CreateOrder::execute()` wraps the order row, all item rows, and coupon claim in one database transaction. Item-write failure rolls the order and coupon count back.
- **Cart/client price tampering:** Product base prices, option prices, quantities, and totals are recalculated server-side at submit. A client/session snapshot price does not directly become the charged price.
- **Money rounding:** `PricingService` converts to integer cents for line, subtotal, coupon, delivery fee, and total calculations. Existing rounding and reconciliation tests pass.
- **Coupons:** Codes are normalized; active window, minimum subtotal, maximum usage, and value are rechecked. The usage cap is enforced by a conditional atomic increment and rolls back with a failed order transaction.
- **Delivery fee:** The current order flow explicitly uses `0.00`, and tests consistently document free delivery. No hidden client-provided delivery fee enters the order.
- **Payment methods:** Checkout accepts only values from `PaymentMethod`; forged values are rejected. Current methods are cash and POS with courier, not browser-confirmed online payments.
- **Tracking IDOR:** Public tracking binds by a unique 40-character random `public_token`, not sequential order ID. The token is not mass assignable, numeric IDs return 404 on the public route, and crawler paths are disallowed.
- **Kitchen/admin authentication:** Kitchen routes require the intended web-authenticated staff account. Filament additionally calls `User::canAccessPanel()` and rejects non-admin kitchen staff from admin resources.
- **Driver IDOR/races:** Driver actions use the authenticated driver, verify assignment ownership, lock transitions, and claim a ready order with a conditional update. Two drivers cannot claim the same order through the implemented path.
- **Order status races:** Kitchen transitions lock and compare the expected current status, preventing two stale boards from skipping a step. Ready/out progression is reserved for the courier flow.
- **CSRF:** Public mutations are Livewire requests under the web middleware; Filament includes CSRF middleware. No application CSRF exclusions were found.
- **XSS:** Customer/catalogue values in application Blade templates are rendered with escaped `{{ ... }}` output. No application `{!! ... !!}`, `x-html`, or direct customer-controlled `innerHTML` sink was found.
- **Mass assignment:** Sensitive `Order::public_token`, `Coupon::used_count`, and `User::is_admin` are not fillable. Checkout supplies a fixed allow-list to order creation.
- **Uploads:** Product upload is admin-only, size-limited, restricted to JPEG/PNG/WebP, stored under generated names on the public disk, and old files are removed only when the model stops referencing them. Existing upload lifecycle tests pass.
- **Mobile checkout wiring:** The sticky submit button has a Livewire loading disable state; deferred form properties are included in the action request. Product option IDs are validated against the open product when initially added. No confirmed mobile-only loss bug was found outside the cross-request idempotency issue above.
- **Secrets:** No Viva or other payment credentials are present in tracked code or environment examples.

## Verification performed

All mutation-capable checks were executed in an isolated copy under `/tmp`, leaving the repository untouched during the audit phase.

- `php artisan test --do-not-cache-result`: **PASS — 250 tests, 250 passed, 1,132 assertions**
- `npm run build`: **PASS — Vite 8.1.0 production build completed**
- `php artisan route:list --except-vendor -v`: inspected **22 application routes** and middleware; Livewire update/upload routes were also inspected
- Migrations: all migration files were reviewed; the complete migration chain is exercised repeatedly by the passing `RefreshDatabase` test suite against SQLite `:memory:`
- Existing static analysis: **none configured** in `composer.json`; no new analyzer was installed
- Existing JS lint script: **none configured** in `package.json`
- `vendor/bin/pint --test`: **FAIL (IGNORE)** on seven formatting-only rules/files; no runtime/security impact and no files were auto-formatted
- Working tree after all Phase 1 checks: clean; no source/config/test file changed before this report

## Final verdict

`NOT READY FOR PRODUCTION`

The system is close and its core price/transaction/authentication design is substantially sound, but the three confirmed HIGH issues are real order-loss/duplication/false-result paths. Production readiness requires those defects to be fixed and covered by targeted regression tests. The four MEDIUM issues should be corrected in the same stabilization pass because they are small, localized validation/revalidation gaps with direct order correctness impact.
