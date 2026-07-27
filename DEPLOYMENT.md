# Deployment

Single shop, single server. PHP 8.3+, MySQL/MariaDB, nginx, Node (build only).

PHP needs **GD with WebP support** (`php8.3-gd`). Product photos are re-encoded
to WebP on save; without it uploads are stored unconverted and every save logs
`product.image.encode_unavailable`. Verify with:

```bash
php -r 'exit(function_exists("imagewebp") ? 0 : 1);' && echo "GD/WebP ok"
```

## First deploy

```bash
git clone <repo> && cd coffee-delivery
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # public/build is not committed

cp .env.production.example .env  # then fill in DB_*, APP_URL, TRUSTED_PROXIES
php artisan key:generate
php artisan migrate --force

# Creates the admin. Set ADMIN_EMAIL/ADMIN_PASSWORD in .env first, or let the
# seeder print a generated password once. Also loads the demo menu.
php artisan db:seed --force

php artisan storage:link      # product photos 404 without it

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Then remove `ADMIN_PASSWORD` from `.env`.

## PHP upload limits

The photo field accepts up to 8 MB. PHP's defaults are usually 2M, and the
distro default is what bites: the upload dies inside PHP before Laravel sees
it, so Filament shows a stalled progress bar and no error. Raise both in
`php.ini` (FPM pool included) and reload php-fpm:

```ini
upload_max_filesize = 8M
post_max_size = 10M          ; must exceed upload_max_filesize
```

`client_max_body_size 10m;` in the nginx server block too, for the same reason.

## Checklist before taking real orders

- [ ] `APP_ENV=production`, `APP_DEBUG=false` — a debug page leaks the DB password.
- [ ] HTTPS terminated and `SESSION_SECURE_COOKIE=true`.
- [ ] `TRUSTED_PROXIES` set to the reverse proxy. Without it, checkout rate
      limiting sees every customer as the same IP.
- [ ] `storage/` and `bootstrap/cache/` writable by the web user.
- [ ] `php artisan storage:link` done, GD/WebP present, and one uploaded product
      photo actually renders on the storefront as a `.webp`.
- [ ] Admin login works and a second, non-admin staff user can reach `/kitchen`
      but **not** `/admin` (`is_admin = 0`).
- [ ] Place one real order end to end, advance it on the board, cancel a test order.
- [ ] Log rotation: `LOG_STACK=daily`, `LOG_DAILY_DAYS=30`.

## Ongoing

**Backups** — nothing here is reconstructible from the menu; orders are the
business record. Nightly dump, kept off the server:

```bash
mysqldump --single-transaction --quick coffee_delivery | gzip > /backups/coffee-$(date +\%F).sql.gz
```

**Sessions** are stored in the database and hold live carts; the `sessions`
table is pruned by Laravel's own GC. **Queue** is configured (`database`) but
nothing dispatches jobs yet — no worker needed until that changes.

## Deploying a change

```bash
php artisan down
git pull && composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

## Accounts

- **Admin** (`is_admin = 1`): Filament panel at `/admin` — menu, prices, orders.
- **Kitchen staff** (`is_admin = 0`): `/kitchen` and `/kitchen/history` only.

`is_admin` is deliberately not mass-assignable; grant it explicitly:

```bash
php artisan tinker --execute="App\Models\User::where('email','staff@example.gr')->update(['is_admin'=>false]);"
```
