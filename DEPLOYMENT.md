# Deployment

Single shop, single server. PHP 8.3+, MySQL/MariaDB, nginx, Node (build only).

---

## ⛔ Never run these against the live database

> **The production database holds real orders and real catalogue data. We never
> run `db:seed` against it. New products and categories are added from Filament,
> or with a separate, production-safe data migration.**

Forbidden on the production server, without exception:

```bash
php artisan db:seed            # rewrites prices, duplicates option groups
php artisan db:seed --force    # same, and --force removes the only guard rail
php artisan migrate:fresh      # drops every table
php artisan migrate:refresh    # rolls back and replays every migration
php artisan migrate:reset      # rolls back every migration
php artisan db:wipe            # drops every table
composer setup                 # bootstrap script: overwrites .env, key:generate
```

`composer setup` is a **local bootstrap script only**. On a live server
`php artisan key:generate` rotates `APP_KEY`, which invalidates every session
and every encrypted cookie — customers lose their carts, staff are logged out.

Why `db:seed` is destructive here, concretely:

- `OptionGroupSeeder` calls `OptionGroup::create()` unconditionally and the
  table has no unique key on `name`. A second run **duplicates all seven option
  groups and their values**, so every coffee shows "Ζάχαρη" and "Γάλα" twice.
- `DemoMenuSeeder` calls `updateOrCreate()` with a payload containing
  `base_price`, `is_available` and `sort_order`. It **resets every price the
  owner edited in the admin** and flips everything back to available.
- `DemoMenuSeeder` also deletes the legacy `Καφέδες Κρύοι` products and nulls
  `order_items.product_id` for them. Order history survives — `order_items`
  carries its own `product_name` / `base_price` / `selected_options` snapshot —
  but the catalogue rows are gone for good.
- `DatabaseSeeder` **overwrites the admin's password** if `ADMIN_PASSWORD` is
  still present in `.env`.

The only migration command this deployment ever runs is:

```bash
php artisan migrate --force
```

---

## First install — empty database only

> Applies **only** to a brand new server with an empty database, before it has
> accepted a single real order. Once real data exists, this section is closed;
> use "Deploying a change" instead.

```bash
git clone <repo> && cd coffee-delivery
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # public/build is not committed

cp .env.production.example .env  # then fill in DB_*, APP_URL, TRUSTED_PROXIES
php artisan key:generate
php artisan migrate --force
```

Seeding the starter menu and creating the admin — **empty database only**:

```bash
# Set ADMIN_EMAIL/ADMIN_PASSWORD in .env first, or let the seeder print a
# generated password once. There is no default password.
php artisan db:seed --force
```

Then remove `ADMIN_PASSWORD` from `.env` immediately — while it is set, any
later seeder run resets the admin's password.

```bash
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

---

## Deploying a change

The routine path. No seeding, ever.

```bash
php artisan down --retry=60

# 1. Back up first — see "Backups" below. Both the database AND the uploads.

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --pretend    # read the plan first
php artisan migrate --force      # the only migration command we run

php artisan storage:link         # idempotent, safe to repeat
php artisan config:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache

php artisan up
```

No queue worker to restart: `QUEUE_CONNECTION=database` is configured but
nothing dispatches jobs yet. If that changes, restart it after `migrate`.

---

## Storage symlink

**Why:** uploaded photos live in `storage/app/public/products/`. The web server
only serves `public/`. The symlink is what bridges them.

**Apply:**

```bash
php artisan storage:link
```

**Verify:**

```bash
ls -la public/storage        # → public/storage -> ../storage/app/public
php artisan about | grep -i storage
```

**Symptom if missing:** every product photo 404s. The page renders, the image
boxes are empty or show the broken-image glyph. Nothing is logged, because the
404 happens in nginx before PHP is involved.

---

## APP_URL

**Why:** `config/filesystems.php` builds the public disk's URL directly from it:

```php
'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
```

`Product::$image_url` calls `Storage::disk('public')->url($this->image)`, so
**every `/storage/...` image URL on the storefront inherits `APP_URL`**. It must
match the live scheme and host exactly — `https://`, real domain, no trailing
slash, no port.

**Apply:** set it in `.env`, then rebuild the config cache — a cached config
keeps serving the old value:

```bash
php artisan config:clear
php artisan config:cache
```

**Verify:**

```bash
php artisan config:show app | grep -i url
php artisan tinker --execute="dump(config('app.url'), config('filesystems.disks.public.url'));"
```

**Symptom if wrong:** the page and the layout are fine, but every photo is
broken. Right-click an image → "Copy image address" shows `http://127.0.0.1:8000/...`
or a stale domain. Mixed-content blocking hits the `http://` case on an HTTPS
page, so the browser console shows the only real clue.

---

## FILESYSTEM_DISK

**Why:** it sets `filesystems.default`, which is the disk used by bare
`Storage::` calls. The product image pipeline **never relies on it** — every
call site names the disk explicitly:

- `app/Actions/EncodeProductImage.php` — `Storage::disk('public')`
- `app/Observers/ProductObserver.php` — `Storage::disk('public')`
- `app/Models/Product.php` — `Storage::disk('public')->url(...)`
- `app/Filament/Resources/ProductResource.php` — `->disk('public')` on both the
  upload field and the table column

So upload and display are pinned to the same disk by construction, and
`FILESYSTEM_DISK` does not change where photos land or how they are served.
Leave it at `local` unless something else needs it.

**Verify:**

```bash
php artisan config:show filesystems
php artisan tinker --execute="dump(config('filesystems.default'), config('filesystems.disks.public.root'));"
```

---

## PHP upload limits — set them in the FPM pool, not the CLI

**Why:** production serves through nginx + PHP-FPM. `php artisan serve` is not
involved. The photo field accepts up to 8 MB; distro defaults are usually 2M.
An over-limit upload dies inside PHP **before Laravel sees the request**, so
Filament shows a stalled progress bar, no validation error, and nothing in
`storage/logs/`.

**The trap:** `php --ini` and `php -i` report the **CLI** configuration. FPM
loads a different `php.ini` and its own pool file. A CLI check that looks
correct proves nothing about what the web request will get.

**Find the configuration FPM actually loads:**

```bash
php-fpm8.3 -i | grep -E "Loaded Configuration|upload_max_filesize|post_max_size|memory_limit|max_execution_time"
grep -Rn "upload_max_filesize\|post_max_size\|memory_limit\|max_execution_time" /etc/php/8.3/fpm/
```

**Apply** in `/etc/php/8.3/fpm/php.ini` (or the pool's `php_admin_value`):

```ini
upload_max_filesize = 8M     ; must be >= the 8 MB the form accepts
post_max_size       = 10M    ; must exceed upload_max_filesize
memory_limit        = 256M   ; minimum — see below
max_execution_time  = 60     ; re-encoding a large photo is not instant
```

`memory_limit` is the one that gets missed. `EncodeProductImage` decodes the
upload into an uncompressed bitmap: a 6000×8000 photo needs roughly 190 MB for
`imagecreatefromstring` alone, before the resized copy. At the common 128M
default FPM kills the request and the owner sees a bare 500. **256M minimum.**

**Reload and verify:**

```bash
sudo systemctl reload php8.3-fpm
sudo systemctl status php8.3-fpm
php-fpm8.3 -i | grep -E "upload_max_filesize|post_max_size|memory_limit|max_execution_time"
```

Adjust `8.3` to the version actually installed (`php -v`, `ls /etc/php/`).

---

## Nginx upload limit

**Why:** nginx rejects an over-sized body before it ever reaches PHP-FPM, with
a 413 that Filament's uploader surfaces as a silent failure.

**Apply** in the server block:

```nginx
client_max_body_size 10m;   # match post_max_size
```

**Verify:**

```bash
sudo nginx -T | grep -n client_max_body_size
sudo nginx -t
sudo systemctl reload nginx
```

**Symptom if missing:** identical to the PHP limit case — stalled progress bar,
no error. The difference shows up in nginx's access log as a 413.

---

## GD with real WebP support

**Why:** a hard requirement, not a nice-to-have. `EncodeProductImage` is
deliberately tolerant: if `imagewebp()` is unavailable it logs
`product.image.encode_unavailable` and **returns without converting**. The
upload still succeeds and the photo still renders — as an uncompressed JPEG or
PNG, several times the intended size. Nothing breaks loudly. The presence of the
GD extension alone does not prove WebP support; GD can be built without it.

**Apply:**

```bash
sudo apt install php8.3-gd
sudo systemctl restart php8.3-fpm
```

**Verify** — the middle command is the one that matters:

```bash
php -m | grep -i gd
php -r 'var_dump(function_exists("imagewebp"));'
php -r 'print_r(gd_info());'
```

The required result is exactly:

```text
bool(true)
```

and `gd_info()` must show `[WebP Support] => 1`. Check it under FPM too, not
only the CLI — the two can load different module sets:

```bash
sudo php-fpm8.3 -i | grep -i -A3 "^gd$"
```

**Symptom if missing:** uploads keep working, photos keep rendering, and the
product list in `/admin` shows a red ⚠️ next to every affected row
(`ProductResource::imageNeedsAttention`). `storage/logs/` fills with
`product.image.encode_unavailable`.

---

## Re-encoding existing photos

**Why:** photos uploaded before server-side encoding existed are still JPEG or
PNG, and photos written by the first version of the encoder may be rectangular
WebP. Both need a pass. Saving each product in the admin would also do it, but
nobody is going to open forty products by hand.

**Always run the plan first:**

```bash
php artisan products:reencode-images --dry-run
```

The dry run is read-only: it reads each file's header and writes nothing —
no file written or deleted, no product saved, no order or order item touched.
It classifies every photo and prints per-outcome totals:

| Outcome | Meaning |
|---|---|
| `convert` | not WebP — will be re-encoded and the column repointed |
| `re-square` | WebP, but not a ≤600px square — rewritten in place, same path |
| `missing` | the column names a file that is not on the disk — a real run skips it |
| `unreadable` | on disk but GD cannot read the header — left as is, logged |
| `ok` | already a ≤600px WebP square — the encoder's own guard skips it |

Read the plan, confirm the counts look right, confirm the uploads backup exists,
and only then:

```bash
php artisan products:reencode-images
```

**This rewrites files in place and is not reversible without the uploads
backup.** It never touches `orders` or `order_items`.

---

## Backups

Two things need backing up, and only one of them is a database.

**Database** — orders are the business record and nothing here is
reconstructible from the menu:

```bash
mysqldump --single-transaction --quick coffee_delivery | gzip > /backups/db-$(date +\%F).sql.gz
```

**Uploads** — `storage/app/public/products/` holds the only copy of every
product photo. A database dump does not contain them, and the re-encode command
overwrites them in place:

```bash
tar czf /backups/uploads-$(date +\%F).tar.gz storage/app/public/products
```

Both nightly, both kept off the server. Before any deploy, take a fresh pair
and record the commit you are deploying from:

```bash
git rev-parse HEAD > /backups/commit-$(date +\%F).txt
```

---

## Rollback

```bash
php artisan down

git reset --hard $(cat /backups/commit-<date>.txt)
composer install --no-dev --optimize-autoloader
npm ci && npm run build

gunzip < /backups/db-<date>.sql.gz | mysql coffee_delivery
tar xzf /backups/uploads-<date>.tar.gz          # restores pre-WebP originals

php artisan config:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

**Do not use `php artisan migrate:rollback`.** Restoring the dump already
restores the schema, and the `down()` of `add_image_to_products_table` drops the
`image` column — running it would throw away every photo path the restored dump
just brought back.

---

## Frontend build

`public/build` is not committed; it is produced on the server.

```bash
npm ci          # lockfile-exact install, unlike npm install
npm run build
```

**The build needs outbound network access to `fonts.bunny.net`.** `vite.config.js`
self-hosts Commissioner and Inter by downloading them at build time. On a
firewalled droplet the build fails, or the storefront renders in a fallback
system font. Verify the output exists before reloading:

```bash
ls -la public/build/manifest.json
```

---

## Checklist before taking real orders

- [ ] `APP_ENV=production`, `APP_DEBUG=false` — a debug page leaks the DB password.
- [ ] `APP_URL` is the exact live `https://` origin, and the config cache was
      rebuilt after setting it.
- [ ] HTTPS terminated and `SESSION_SECURE_COOKIE=true`.
- [ ] `TRUSTED_PROXIES` set to the reverse proxy. Without it, checkout rate
      limiting sees every customer as the same IP.
- [ ] `storage/` and `bootstrap/cache/` writable by the web user.
- [ ] `php artisan storage:link` done, GD/WebP present, and one uploaded product
      photo actually renders on the storefront as a `.webp`.
- [ ] FPM `upload_max_filesize`/`post_max_size`/`memory_limit`/`max_execution_time`
      verified through `php-fpm8.3 -i`, not the CLI.
- [ ] nginx `client_max_body_size` verified through `nginx -T`.
- [ ] `products:reencode-images --dry-run` reviewed, uploads backup taken.
- [ ] Admin login works and a second, non-admin staff user can reach `/kitchen`
      but **not** `/admin` (`is_admin = 0`).
- [ ] Place one real order end to end, advance it on the board, cancel a test order.
- [ ] Log rotation: `LOG_STACK=daily`, `LOG_DAILY_DAYS=30`.
- [ ] `ADMIN_PASSWORD` is **not** present in `.env`.

---

## Ongoing

**Sessions** are stored in the database and hold live carts; the `sessions`
table is pruned by Laravel's own GC. **Queue** is configured (`database`) but
nothing dispatches jobs yet — no worker needed until that changes.

---

## Accounts

- **Admin** (`is_admin = 1`): Filament panel at `/admin` — menu, prices, orders.
- **Kitchen staff** (`is_admin = 0`): `/kitchen` and `/kitchen/history` only.

`is_admin` is deliberately not mass-assignable; grant it explicitly:

```bash
php artisan tinker --execute="App\Models\User::where('email','staff@example.gr')->update(['is_admin'=>false]);"
```

---

## Known technical debt

Recorded, not addressed in this pass:

- `bootstrap/app.php` reads `TRUSTED_PROXIES` through `env()`, which runs before
  the `.env` file is loaded. The value is always ignored and the fallback
  `['127.0.0.1', '::1']` always wins. Correct by accident for nginx on the same
  host; **wrong the moment a Cloudflare or external load-balancer IP is
  configured**, which would put every customer in one rate-limit bucket.
- `CreateOrder` derives `display_number` from a `whereDate(...)->lockForUpdate()->count()`.
  `lockForUpdate()` is a no-op on SQLite, so the test suite never exercises the
  locking; on MySQL the `whereDate()` prevents the `created_at` index from being
  used. There is no unique constraint on the result.
- Seeder name matching relies on `=`, which is case- and accent-insensitive on
  MySQL's `utf8mb4_unicode_ci` but exact on SQLite. Tests cannot catch the
  difference.
- `ProductResource` exposes a delete bulk action; `ProductObserver::deleted()`
  removes the uploaded file permanently, with no soft delete.
