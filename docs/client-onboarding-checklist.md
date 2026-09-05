# Client onboarding checklist

Per-client steps to take this codebase from a fresh clone to a live shop.
Deployment mechanics live in `DEPLOYMENT.md`; the print worker contract lives in
`docs/printing-bridge.md`. This file only covers what changes **per client**.

Work top to bottom — later steps assume the domain and `.env` are settled.

## 1. Identity

- [ ] `APP_NAME="<Client Name>"` in `.env` — this is the PWA **app name**, the
      `<title>`, and the mail "from" name.
- [ ] Manifest `short_name` — the home-screen label. Keep it under ~12
      characters or Android truncates it. It is set in
      `public/manifest.webmanifest`, not from `APP_NAME`.
- [ ] Manifest `name`, `description`, `lang` match the client.

## 2. Brand colours

- [ ] Pick the primary brand colour. Set it in **three** places, identical:
      `theme_color` and `background_color` in `public/manifest.webmanifest`,
      and `<meta name="theme-color">` in `resources/views/layouts/app.blade.php`.
- [ ] Update the brand tokens in `resources/css/app.css` (`--accent`,
      `--espresso`, …) if the client's palette differs.
- [ ] `background_color` is the splash screen. Matching it to the icon
      background gives one continuous surface; matching it to the page's first
      paint avoids a colour flash. Pick one deliberately.

## 3. Logo and icons

- [ ] Get the highest-resolution logo the client has. 512px on the long edge
      or better, transparent PNG preferred — everything below is generated
      from it, so a small source stays small.
- [ ] Store it at `public/images/<client>-logo-primary.png`.
- [ ] Generate the full icon set:

      python3 scripts/generate-pwa-icons.py public/images/<client>-logo-primary.png -b '#RRGGBB'

      This writes `public/icons/*` and `public/favicon.ico`, and prints the
      manifest `icons` array to paste in. Requires `pip install Pillow`.
- [ ] Confirm all of these exist: `icon-{72..512}.png`,
      `icon-maskable-{192,512}.png`, `apple-touch-icon.png`,
      `favicon-{16,32}.png`, `favicon.ico`.
- [ ] Check the maskable pair at <https://maskable.app> — no text clipped in
      the circle or squircle preview.
- [ ] **Delete every icon from the previous client.** No leftover file, and no
      leftover `src` in the manifest. Cross-check that each manifest entry
      resolves and that `public/icons/` holds nothing unreferenced.

## 4. Manifest and service worker

- [ ] `start_url` and `scope` are `/` unless the shop is served from a
      subdirectory.
- [ ] Manifest parses as JSON and is served as `application/manifest+json`.
- [ ] **Bump `CACHE_VERSION` in `public/service-worker.js`** whenever an icon,
      the favicon or the manifest changes. The worker caches `/icons/`,
      `/favicon.ico` and `/manifest.webmanifest` cache-first with no
      revalidation, so without a bump existing installs keep the old branding
      forever. Do not change the caching rules themselves.

## 5. Domain and application

- [ ] DNS pointed, TLS issued. **HTTPS is mandatory** — a service worker will
      not register over plain HTTP, so the install prompt never appears.
- [ ] `APP_URL=https://<domain>`, `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `APP_KEY` generated (`php artisan key:generate`).
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` set.
- [ ] `TRUSTED_PROXIES` set if behind nginx or Cloudflare, otherwise redirects
      and client IPs are wrong.
- [ ] Database created; `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` set.

## 6. Mail (SMTP)

- [ ] `MAIL_MAILER=smtp` plus `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
      `MAIL_PASSWORD`, `MAIL_SCHEME`.
- [ ] `MAIL_FROM_ADDRESS` on the client's own domain — a mismatched sender
      lands in spam.
- [ ] SPF/DKIM published for that domain.
- [ ] Send one real order confirmation and confirm it arrives, not just that
      the queue drained.

## 7. Payments (Viva.com)

- [ ] `VIVA_ENABLED=true` only once the credentials are confirmed working.
- [ ] `VIVA_CLIENT_ID`, `VIVA_CLIENT_SECRET`, `VIVA_SOURCE_CODE`,
      `VIVA_ENVIRONMENT` (`demo` → `production` at go-live).
- [ ] `VIVA_WEBHOOK_VERIFICATION_KEY` set. Register the webhook against
      `https://<domain>/payments/viva/webhook` — Viva calls `GET` on that URL
      once to verify, then `POST`s events to it.
- [ ] `VIVA_RECONCILIATION_MERCHANT_ID` / `VIVA_RECONCILIATION_API_KEY` for
      missed-webhook recovery. These are the Merchant ID / API Key, **not** the
      OAuth credentials above.
- [ ] Scheduler running, so `viva:reconcile-pending-payments` fires.
- [ ] One real card payment end to end, then one deliberate abandonment.
- [ ] See `VIVA_WALLET_INTEGRATION.md`.

## 8. Store configuration

- [ ] Business hours: admin → Store Settings. Set the weekly intervals,
      `accepting_orders`, and the closed message. `APP_TIMEZONE` and
      `config/store.php` must both be the client's timezone.
- [ ] `MINIMUM_ORDER_AMOUNT` agreed with the client.
- [ ] Delivery fees / zones: **not implemented.** `CreateOrder` hardcodes
      `$deliveryFee = 0.00` and there is no zone concept. The `orders.delivery_fee`
      column exists and is persisted, so a flat fee is a small change, but a
      client who needs per-area pricing needs real work scoped before the sale.
      Confirm the client expects free delivery, or budget for it.
- [ ] Categories, products, option groups, coupons entered.
- [ ] Product images uploaded.

## 9. Accounts and access

- [ ] `ADMIN_EMAIL` / `ADMIN_PASSWORD` set, then `php artisan db:seed` — used
      once at seed time only.
- [ ] Log in at `/admin`, change the password, and clear `ADMIN_PASSWORD`
      from `.env`.
- [ ] `KITCHEN_AVAILABILITY_PIN_HASH` — store the **hash**, never the PIN
      (`Hash::make('123456')` in tinker). Give the PIN to staff separately.
- [ ] Hand over credentials through something that is not email.

## 10. Thermal printer

- [ ] `PRINT_WORKER_TOKEN` — one high-entropy secret, identical in Laravel's
      `.env` and the worker's. Generate a fresh one per client.
- [ ] Worker `.env`: `LARAVEL_URL` (no `/api/printing` suffix),
      `PRINT_WORKER_DB` on persistent storage, and either
      `PRINTER_ADDRESS=<ip>:9100` (network) or `PRINTER_DEVICE=/dev/usb/lp0` (USB).
- [ ] `PRINTER_BACKEND` is a real backend, not the `stdout` dev default.
- [ ] `PRINTER_WIDTH_DOTS` matches the paper (576 for 80mm).
- [ ] Worker starts on boot and survives a shop power cut.
- [ ] Place a test order and confirm the receipt prints once, not twice.
- [ ] See `docs/printing-bridge.md`.

## 11. Backups and monitoring

- [ ] Nightly `mysqldump` **and** a `storage/app/public/products` archive —
      the database alone will not restore the shop. See "Backups" in
      `DEPLOYMENT.md`.
- [ ] Restore rehearsed once into a scratch database. An untested backup is
      not a backup.
- [ ] Record the deployed commit alongside each backup.
- [ ] `SENTRY_LARAVEL_DSN` set, `LOG_LEVEL=error`.
- [ ] Uptime check on the menu page, alerting somewhere a human reads.
- [ ] `ORDER_PII_ANONYMIZATION_DAYS` set to the agreed retention period.
      See `DATA_RETENTION.md`.

## 12. Install test — do this last, on the real HTTPS domain

A LAN IP or plain HTTP suppresses the install prompt even when the PWA is
valid, so this step is only meaningful against production.

- [ ] Android/Chrome: DevTools → Application → Manifest reports no errors,
      the service worker is activated, and "Install" is offered.
- [ ] Android: install it. The launcher icon shows the client's logo, uncropped,
      with no white or black box behind it.
- [ ] Android: launch from the home screen — standalone, no browser chrome,
      correct splash colour.
- [ ] iOS/Safari: Share → Add to Home Screen. Correct icon and correct title.
- [ ] iOS: launch it — standalone, and the status bar is legible against the
      header.
- [ ] Place one real order from an installed instance on each platform.
- [ ] Re-test after any icon change, with `CACHE_VERSION` bumped, on a device
      that already had it installed.
