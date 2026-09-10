# Αρχιτεκτονικές αποφάσεις και απογραφή — Task 0

Ημερομηνία απογραφής: 2026-09-10
Κατάσταση Git πριν από την αλλαγή τεκμηρίωσης:

- branch: `main`
- HEAD: `4f2142dd49135c09a57f3c12030778cf3fc34363`
- `main` και `origin/main`: ίδιο SHA
- working tree: καθαρό
- μοναδικά refs που βρέθηκαν: `main`, `origin/main`

Αυτό είναι το architectural ground truth για τη μετάβαση από ένα customer-specific
delivery app σε ένα canonical προϊόν με ανεξάρτητες εγκαταστάσεις. Το Task 0 δεν
αλλάζει runtime συμπεριφορά, δεδομένα, σχήμα ή υποδομή.

## Δεσμευτικές αποφάσεις

1. Το Laravel παραμένει το application framework.
2. Υπάρχει ένα canonical repository.
3. Δεν επιτρέπονται customer-specific branches ή runtime code forks.
4. Κάθε πελάτης παραμένει προς το παρόν ανεξάρτητο mono-tenant instance.
5. Κάθε instance κατέχει τη δική του βάση δεδομένων, environment configuration
   και uploaded/runtime data.
6. Τα μη μυστικά, business-editable settings ζουν στη βάση δεδομένων.
7. Environment/infrastructure configuration και secrets ζουν εκτός των business
   settings.
8. Το branding είναι runtime configuration με CSS variables· δεν υπάρχει
   customer-specific frontend build.
9. Presets όπως `cafe`, `grill` και `restaurant` είναι μόνο provisioning defaults.
10. Το runtime application logic δεν επιτρέπεται ποτέ να κάνει branch σε
    `business_type` ή preset.
11. Η runtime μεταβλητότητα εκφράζεται μόνο με explicit capabilities/features.
12. Επεκτείνουμε τους υπάρχοντες μηχανισμούς αντί να τους διπλασιάζουμε.
13. Τα application releases χτίζονται μία φορά, εκτός των customer production
    instances.
14. Το ίδιο ακριβώς immutable release artifact αναπτύσσεται σε κάθε instance.
15. Το production deployment έχει manual release gate.
16. Το code deployment υποστηρίζει atomic release switching και health
    verification.
17. Τα database migrations είναι backward-compatible / expand-first, ώστε code
    rollback να μη χρειάζεται automatic database restore.
18. Απαιτούνται backup και δοκιμασμένες διαδικασίες emergency restore.
19. Multi-tenancy δεν εισάγεται επειδή απλώς αυξήθηκε ο αριθμός πελατών· απαιτεί
    αποδεδειγμένο operational pain.
20. Δεν προστίθεται αρχιτεκτονική ή dependency speculative.

## Υφιστάμενη εφαρμογή: ground truth

### Business, catalogue και store settings

Υπάρχει ήδη ο σωστός πυρήνας για τα business-editable settings:

- `app/Models/StoreSetting.php` είναι singleton (`id = 1`) και σήμερα περιέχει
  `accepting_orders`, `opening_hours` και `closed_message`. Αναδημιουργείται
  fail-closed όταν λείπει.
- Η migration `2026_08_18_120000_create_store_settings_table.php` δημιουργεί και
  αρχικοποιεί αυτό το singleton.
- `app/Filament/Pages/StoreSettings.php` και
  `resources/views/filament/pages/store-settings.blade.php` διαχειρίζονται
  εβδομαδιαίο ωράριο και closed message. Το
  `app/Filament/Widgets/StoreOrdersStatus.php` αλλάζει το manual
  `accepting_orders`.
- `app/Support/StoreSchedule.php` είναι ο runtime reader του singleton και του
  `config/store.php` timezone. Το ωράριο και το manual override ελέγχονται στο
  storefront και πριν από checkout.

Τα business models είναι `Category`, `Product`, `OptionGroup`, `OptionValue`,
`Coupon`, `Order`, `OrderItem`, `Driver`, `DriverShift`,
`OrderDriverTransition`, `PrintJob`, `StoreSetting` και `User`. Η δομή του
catalogue είναι γενική: categories/products, reusable option groups/values και
pivots category/product-to-option-group. Τα orders διατηρούν snapshots των
items/options/prices.

Οι domain migrations δημιουργούν catalogue, options και order snapshots, μετά
προσθέτουν admin access, tracking token, product image, coupons, driver flow,
Viva fields, customer email, store settings, PII retention, unit price και
print jobs. Οι περισσότερες προηγούμενες migrations έχουν destructive `down()`
operations (drop tables/columns/indexes): αυτό είναι ιστορικό γεγονός, όχι
πρότυπο για μελλοντικές αλλαγές.

Relevant application services/actions είναι `CartService`, `PricingService`,
`OptionsPresenter`, `VivaWalletService`, `StoreSchedule`, `VivaPaymentHealth`,
`CreateOrder`, status/driver transitions, product image storage/encoding και
the kitchen print-job bridge. Δεν υπάρχει δεύτερο settings system.

Το Filament έχει resources για categories, products, option groups, coupons και
orders, τη σελίδα Store Settings, widgets για order status/quick links/Viva
health και staff login. Δεν υπάρχει resource/page για generic business profile
ή branding ακόμη.

### Config και runtime boundaries

| Πεδίο | Σημερινή πηγή | Παρατήρηση |
| --- | --- | --- |
| Store schedule / closed message / accepting orders | `store_settings` | business-editable, runtime DB source |
| Minimum order amount | `MINIMUM_ORDER_AMOUNT` → `config/cart.php` | environment config σήμερα |
| Viva enablement και credentials | `VIVA_*` → `config/services.php` | environment feature switch + secrets |
| Kitchen availability PIN | `KITCHEN_AVAILABILITY_PIN_HASH` → `config/kitchen.php` | secret εκτός DB |
| Print worker bearer token | `PRINT_WORKER_TOKEN` → `config/printing.php` | secret εκτός DB |
| Application/store display name | `APP_NAME` → `config/app.php` | environment τώρα, χρησιμοποιείται και ως infrastructure prefix |
| Public upload URL | `APP_URL` → `config/filesystems.php` | infrastructure/runtime deployment config |

Η `.env.example` ακόμη προτείνει `DB_DATABASE=coffee_delivery`. Το production
template δεν θέτει database name, αλλά διατηρεί `APP_NAME="Delivery Menu"`.

### Customer/store-type references

Η αναζήτηση σε PHP, Blade, JS, CSS, tests, seeders και config για
`business_type`, `cafe`, `coffee`, `grill`, `restaurant`, `Leonidas` και
`leonidas` έδωσε τα εξής:

- Δεν υπάρχει `business_type`, `grill`, `restaurant`, `Leonidas` ή `leonidas`
  σε application runtime code/config. Δεν υπάρχει preset ή tenancy mechanism.
- Το `DemoMenuSeeder`, `OptionGroupSeeder`, `BrownSugarSweetenerSeeder` και οι
  αντίστοιχες tests περιέχουν coffee menu, επιλογές ζάχαρης/γάλακτος και
  placeholder cafe δεδομένα. Αυτά είναι provisioning/demo catalogue data,
  παρότι το τρέχον seeding δεν είναι ασφαλές να επαναληφθεί σε production.
- `DEPLOYMENT.md`, `.env.example`, printing-worker README και test fixture names
  περιέχουν `coffee-delivery`/`coffee_delivery`. Είναι documentation/default
  naming, όχι business-type branching.
- Υπάρχει όμως customer/domain-specific **runtime logic**, όχι απλώς δεδομένα:
  `app/Services/OptionsPresenter.php` canonicalizes και μορφοποιεί με τα fixed
  option names `Ζάχαρη`, `Γλυκαντικό`, `Σκέτος`, `Ζάχαρη`; `MenuPage` εφαρμόζει
  την ίδια υπόθεση στο cart και το product-modal Blade (`x-show` για sweetener),
  και `CreateOrder` canonicalizes ξανά submitted options. Η
  `CoffeeSweetnessOptionTest` κλειδώνει αυτή τη συμπεριφορά. Αυτά είναι
  application-logic hardcodes και conflict με canonical, business-agnostic
  runtime.
- Η υπόλοιπη αναφορά σε coffee σε models, products και option values είναι
  catalogue/demo data ή test fixture, όχι branch του application ανά store
  type.

### Branding και frontend build

Σήμερα το branding είναι διάσπαρτο και κυρίως compile-time/static:

- Το όνομα προέρχεται από `APP_NAME` και εμφανίζεται σε storefront page title,
  storefront headings, order board και mail/config prefixes. Το admin quick-link
  widget γράφει όμως κυριολεκτικά `Delivery Menu`.
- Το `resources/css/app.css` ορίζει fixed amber/espresso CSS variables
  (`--accent`, `--accent-hover`, `--accent-light`, `--accent-text`,
  `--espresso`) με cafe-specific comments. Πολλά Blade/JS χρησιμοποιούν τις
  variables, αλλά order board, driver/kitchen views και `app.js` περιέχουν και
  fixed `amber-*` Tailwind utility classes.
- Filament primary color είναι static `Color::Amber` στο
  `AdminPanelProvider`.
- `resources/views/layouts/app.blade.php` έχει static amber `theme-color` και
  static Apple app title `Delivery`; `layouts/kitchen.blade.php` έχει static
  theme color/title.
- `public/manifest.webmanifest` έχει static `name: Coffee Delivery`,
  `short_name: Delivery` και amber `theme_color`. Τα PWA icons/favicon είναι
  checked-in public binary assets. Το `scripts/generate-pwa-icons.py` ξαναγράφει
  αυτά τα assets από client logo και background color.

Το `@vite` φορτώνει `resources/css/app.css` και `resources/js/app.js`.
`public/build` είναι ignored/non-committed και το σημερινό deployment τρέχει
`npm ci && npm run build` στον production server. Επομένως οποιαδήποτε αλλαγή
σε source CSS, JS, Tailwind utilities, Vite assets/fonts ή generated PWA icons
χρειάζεται rebuild/release. Αλλαγή μόνο του `APP_NAME` είναι runtime config
αλλαγή, αλλά απαιτεί cache refresh όπου config είναι cached. Το Vite build
κατεβάζει Bunny fonts, άρα έχει και outbound-network dependency στο build.

### Υφιστάμενες capabilities και switches

Καταγράφονται μόνο όσα υπάρχουν σήμερα:

| Capability | Υφιστάμενη συμπεριφορά / switch |
| --- | --- |
| Delivery/courier | Υπάρχει end-to-end driver flow (`drivers`, PIN login, shifts, assignment, pickup/out-for-delivery/delivered transitions). Δεν έχει customer capability flag: είναι ενεργό runtime functionality. |
| Pickup/take-away | Υπάρχει μόνο public copy «delivery και take away». Δεν υπάρχει order type, pickup workflow, pickup payment rule ή capability switch· checkout απαιτεί address/phone. |
| Delivery fee / zone | Schema και pricing υποστηρίζουν `delivery_fee`, αλλά δεν υπάρχει editable fee/zone configuration ή selector· η τρέχουσα order creation χρησιμοποιεί τη διαθέσιμη delivery flow. |
| Catalogue availability | Category `is_active` και product `is_available`, διαχειρίσιμα από admin/kitchen availability UI. |
| Product options | General category/product option-group pivots, single/multi selection, required/min/max/default και price deltas. Δεν υπάρχει feature switch. |
| Coupons | Coupon resource και per-coupon `is_active`, schedule, minimum, maximum uses. Δεν υπάρχει global coupons capability flag. |
| Online payments | Cash και courier POS είναι πάντα checkout methods. Viva εμφανίζεται μόνο όταν `VIVA_ENABLED=true`; credentials/environment/webhook keys μένουν environment secrets. |
| Kitchen printing | Accepted fulfilment orders δημιουργούν persistent `print_jobs`; API προστατεύεται από `PRINT_WORKER_TOKEN`. Δεν υπάρχει global print capability setting. Rust worker έχει δικό του environment, local SQLite state και προαιρετικό manual systemd unit/backend selection. |
| Store availability | Singleton DB manual accepting-orders toggle, weekly hours και closed message. |
| Minimum order | `MINIMUM_ORDER_AMOUNT` environment/config value· δεν είναι DB setting. |
| Kitchen access | `KITCHEN_AVAILABILITY_PIN_HASH` environment secret. |

### Deployment, data και operational assumptions

Το repository έχει `DEPLOYMENT.md`, `.env.production.example`, το Rust
`printing-worker/README.md` και sample systemd unit. Δεν υπάρχουν CI workflow,
release artifact script, release-directory layout, atomic-switch script ή
deployment automation στο tracked tree.

Η σημερινή documented production διαδικασία είναι in-place, χειροκίνητη και
τρέχει `git pull`, `composer install --no-dev --optimize-autoloader` και
`npm ci && npm run build` **πάνω στον production server**, πριν από
`migrate --pretend`/`migrate --force`, caches και `artisan up`. Το πρώτο install
κάνει επίσης Composer/NPM build στο server. Άρα σήμερα δεν υπάρχει build-once
immutable artifact, formal manual release gate, atomic release switching ή
documented health check in deploy. Το Laravel έχει `/up` health route από
`bootstrap/app.php`, αλλά η διαδικασία deploy δεν την επαληθεύει.

Η documented rollback χρησιμοποιεί `git reset --hard`, ξανατρέχει Composer/NPM
build και επαναφέρει DB/uploads backup. Αυτό συγκρούεται με την επιθυμητή
code-only rollback πάνω σε expand-first schema και δεν αποτελεί atomic release
switch.

Κάθε σημερινό instance είναι ήδη πρακτικά mono-tenant: μία database connection
per `.env`, one app directory και local runtime data. Δεν υπάρχει tenant id,
tenant resolver ή shared customer database.

Storage/database assumptions:

- Production DB: MySQL/MariaDB; testing: in-memory SQLite.
- Sessions, cache και queue tables είναι database-backed by default; τα orders,
  catalogue, settings, print-job records και users είναι στην ίδια instance DB.
- Product uploads βρίσκονται στο `storage/app/public/products`; το `public/storage`
  symlink τα εκθέτει και οι URLs εξαρτώνται από `APP_URL`.
- `storage/` και `bootstrap/cache/` πρέπει να είναι writable από web user.
- Local private disk: `storage/app/private`; logs: `storage/logs`.
- Ο printing worker έχει ανεξάρτητο persistent SQLite file (προτεινόμενο
  `/var/lib/kitchen-print-worker/worker.sqlite`) που πρέπει να διατηρείται και
  να λαμβάνεται υπόψη στο backup/restore του συγκεκριμένου instance.

Το documentation περιγράφει manual nightly/off-server database και upload
backups και manual emergency restore. Δεν βρέθηκε checked-in backup job ούτε
evidence από test exercise της restore διαδικασίας.

### Tests: baseline πριν από architectural changes

Η πλήρης Laravel suite τρέχθηκε στο καθαρό `main` με:

```sh
./vendor/bin/phpunit --testdox
```

Αποτέλεσμα: **PASS — 418 tests, 2411 assertions, 29.769 s** (PHP 8.3.6,
PHPUnit 12.5.30). Τα suites είναι `tests/Unit` και `tests/Feature`, με
in-memory SQLite και array cache/session/queue στο `phpunit.xml`.

Το Rust printing worker έχει δικές του documented Cargo checks, αλλά δεν ήταν
μέρος του Laravel baseline του Task 0.

## Συγκρούσεις με τις δεσμευτικές αποφάσεις

1. **Branding δεν είναι runtime configuration.** Colors, several labels,
   Filament color, PWA metadata/icons και visual styling είναι source/static
   assets. Αλλαγή client branding απαιτεί source/assets change και build.
2. **Υπάρχει coffee-specific runtime logic.** Η επιλογή sweetness/sweetener
   επεξεργάζεται με hard-coded domain labels σε PHP και Blade, αντί να είναι
   generic catalogue behavior.
3. **Η παραγωγή χτίζει per customer/per server.** Composer και NPM/Vite
   τρέχουν μέσα στα production instances, αντί για one immutable artifact.
4. **Deploy/rollback δεν είναι atomic.** Είναι in-place git working tree
   mutation και destructive `git reset --hard` restore path, χωρίς release
   directories/current symlink/health gate.
5. **Δεν υπάρχει τεκμηριωμένο manual release gate ή deployed health
   verification.** Υπάρχει `/up`, αλλά δεν αποτελεί υποχρεωτικό step.
6. **Rollback προϋποθέτει DB restore.** Η υπάρχουσα διαδικασία το απαιτεί και
   οι ιστορικές migrations έχουν destructive rollback operations. Μελλοντικές
   migrations πρέπει να είναι expand-first/compatible.
7. **Backup/restore δεν είναι demonstrably tested.** Υπάρχουν οδηγίες και
   απαιτήσεις backup, όχι checked-in automated procedure ή recorded restore
   exercise.
8. **Business-editable profile/branding δεν έχει ακόμη θέση στη DB.** Το
   υπάρχον `StoreSetting` καλύπτει operational availability μόνο. Η λύση είναι
   να επεκταθεί αυτός ο singleton μηχανισμός, όχι να δημιουργηθεί νέος.

## Προτεινόμενο ελάχιστο Task 1 patch (δεν υλοποιήθηκε εδώ)

Σκοπός: να δημιουργηθεί η ελάχιστη runtime business-profile/branding βάση,
χωρίς αλλαγή σε catalogue, order, payment, delivery, preset ή deployment flow.

1. Προσθήκη μόνο additive/nullable-or-safe-default columns στη υπάρχουσα
   `store_settings` migration chain για business display name, logo path και
   explicit brand color tokens που χρειάζεται το storefront. Να γίνει backfill
   με τα σημερινά visual defaults, ώστε το υπάρχον customer instance να
   αποδίδει το ίδιο.
2. Επέκταση μόνο των `StoreSetting`, `StoreSettings` Filament page και ενός
   shared view-data/composer path, όχι νέα model/table/settings subsystem. Τα
   secrets, `APP_URL`, payment credentials, worker token και infrastructure
   config μένουν στο environment.
3. Injection των DB color values ως validated CSS custom properties στο public
   layout και αντικατάσταση των customer-facing static color/meta/name uses με
   αυτά τα runtime values. Το frontend bundle παραμένει κοινό και δεν
   ξαναχτίζεται ανά customer.
4. Προσθήκη focused tests για singleton defaults, validation, rendered CSS
   variables/metadata και no-regression fallback. Καμία `business_type`, preset
   ή capability flag δεν εισάγεται σε αυτό το patch.

Το Task 1 δεν πρέπει να μεταφέρει ακόμη το coffee-specific option behavior,
να ενεργοποιήσει/απενεργοποιήσει delivery/pickup/printing, να αλλάξει πληρωμές
ή να αγγίξει production data. Η γενίκευση των coffee option hardcodes και η
release architecture είναι επόμενα, χωριστά patches με tests και migration
compatibility review.

## Task 4 — Immutable release + provision/deploy/restore tooling

Εύρημα πριν την υλοποίηση: το σημερινό `DEPLOYMENT.md`/`.env.production.example`
υποθέτουν MySQL/MariaDB για το ζωντανό instance (Leonidas), ενώ το
`config/database.php` έχει ήδη πλήρως διαμορφωμένη σύνδεση `sqlite` με
`journal_mode: WAL` και το fallback default του Laravel είναι `sqlite`. Το νέο
tooling κτίζεται γύρω από SQLite ως το κανονικό instance DB, αλλά το βήμα
backup στο `deploy-instance.sh` διαβάζει το πραγματικό `DB_CONNECTION` από το
`.env` κάθε instance και διαλέγει μηχανισμό (`sqlite3 .backup` ή `mysqldump`),
ώστε το ίδιο tooling να καλύπτει και το υπάρχον MySQL instance μέχρι το Task 5
να το μεταφέρει. Άλλο εύρημα: το `routes/console.php` έχει δύο πραγματικά
scheduled commands (`viva:reconcile-pending-payments`, `orders:anonymize-
personal-data`) που δεν αναφέρονταν πουθενά ως λειτουργική απαίτηση deploy·
το `provision-instance.sh` τυπώνει τη μία γραμμή cron που χρειάζεται.

Λειτουργικό μοντέλο, χωρίς Docker/Kubernetes/Ansible/Forge/tenancy:

```
BUILD ONCE  (scripts/build-release.sh, εκτός production instance)
  → git archive HEAD (μόνο tracked αρχεία — .env/vendor/node_modules ποτέ
    δεν μπαίνουν στο artifact by construction, όχι με exclude-list)
  → composer install --no-dev, npm ci && npm run build, μέσα στο export
  → πλήρες test suite ως gate (ή ρητό --skip-tests)
  → tar.gz + SHA256 + JSON metadata (git SHA, release id) κάτω από dist/

MANUAL RELEASE GATE
  → ο operator αποφασίζει πότε/σε ποιο instance πάει ένα artifact·
    τίποτα δεν κάνει αυτόματο deploy σε push.

DEPLOY ΤΑ ΙΔΙΑ BYTES (scripts/deploy-instance.sh, ένα instance τη φορά)
  → επαλήθευση SHA256 αν δόθηκε checksum file
  → extract σε νέο releases/<id>/, ποτέ overwrite υπαρχοντος release dir
  → symlink storage/ και .env μέσα στο candidate από το instance-owned shared/
  → backup της DB πριν από migrate (SQLite-safe .backup, όχι raw file copy)
  → php artisan migrate --force, μετά config:cache/route:cache/view:cache
  → pre-switch sanity check (php artisan about) πριν αγγίξει το current
  → atomic switch: ln -s σε προσωρινό link + mv -T πάνω στο current
  → health check στο /up (ή τοπικό artisan boot check αν λείπει APP_URL)
  → αποτυχία health check: current γυρνάει αυτόματα στο προηγούμενο release,
    η DB ΔΕΝ γυρνάει πίσω αυτόματα, exit μη-μηδενικό με ρητό recovery state

CODE ROLLBACK = symlink
  → το current είναι το μόνο group truth· επαναφορά σε προηγούμενο release
    είναι η ίδια atomic mv λειτουργία, χωρίς git/composer/npm στο instance.

DB RESTORE = ξεχωριστό, χειροκίνητο (scripts/restore-instance.sh)
  → ποτέ δεν καλείται αυτόματα από failed deploy
  → απαιτεί ρητό --backup-file και --yes, φτιάχνει safety copy πριν
    αντικαταστήσει τίποτα, ελέγχει integrity πριν και μετά.
```

Ακριβείς εντολές operator:

```bash
# build (developer machine / build box, καθαρό git tree)
scripts/build-release.sh

# πρώτη φορά για ένα νέο instance
scripts/provision-instance.sh --instance-root /var/www/delivery-instances/<name> \
    --env-file /path/to/filled-in.env \
    --artifact dist/delivery-<release-id>.tar.gz \
    --checksum-file dist/delivery-<release-id>.tar.gz.sha256

# επόμενα deploys σε υπάρχον instance
scripts/deploy-instance.sh --instance-root /var/www/delivery-instances/<name> \
    --artifact dist/delivery-<release-id>.tar.gz \
    --checksum-file dist/delivery-<release-id>.tar.gz.sha256

# το ίδιο artifact σε πολλά instances, σταματάει στο πρώτο failure
scripts/deploy-all.sh --artifact dist/delivery-<release-id>.tar.gz \
    --instances-file deploy/instances.txt

# emergency DB restore — ξεχωριστή, ρητή ενέργεια
scripts/restore-instance.sh --instance-root /var/www/delivery-instances/<name> \
    --backup-file /path/to/backup.sqlite --yes
```

Instance layout κάτω από `--instance-root`:

```
<instance-root>/
├── current -> releases/<active-id>/
├── releases/<id>/           ανοσοποιημένο, ό,τι είχε το artifact + symlinked shared/
└── shared/                  instance-owned, επιζεί από κάθε release
    ├── .env
    ├── database/database.sqlite   (μόνο όταν DB_CONNECTION=sqlite)
    ├── storage/...
    └── backups/              pre-deploy backups + pre-restore safety copies
```

Ο Rust `kitchen-print-worker` παραμένει εντελώς εκτός αυτού του κύκλου: δικό
του systemd unit, δικό του μόνιμο SQLite state κάτω από `/var/lib/kitchen-
print-worker/`, δεν αγγίζεται/επανεκκινείται από το Laravel deploy.
