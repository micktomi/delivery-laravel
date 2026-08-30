# Viva.com Smart Checkout scaffold

Η ενσωμάτωση είναι scaffold και παραμένει απενεργοποιημένη από προεπιλογή. Με `VIVA_ENABLED=false` η Viva δεν εμφανίζεται στο checkout και δεν μπορεί να ξεκινήσει νέα online πληρωμή. Οι υπάρχουσες πληρωμές `cash` και `pos_courier` διατηρούν την ίδια ροή.

## Ρυθμίσεις

```env
VIVA_ENABLED=false
VIVA_CLIENT_ID=
VIVA_CLIENT_SECRET=
VIVA_SOURCE_CODE=
VIVA_ENVIRONMENT=demo
VIVA_RECONCILIATION_MERCHANT_ID=
VIVA_RECONCILIATION_API_KEY=
```

Το `VIVA_ENVIRONMENT` δέχεται `demo` ή `production` (`live` γίνεται επίσης δεκτό ως production alias). Μετά την αλλαγή env/config απαιτείται το συνήθες `php artisan config:clear` ή rebuild του production config cache. Πριν από δοκιμή χρειάζεται και εκτέλεση του νέου migration.

Τα `VIVA_RECONCILIATION_MERCHANT_ID` και `VIVA_RECONCILIATION_API_KEY` είναι τα Merchant API credentials για το Viva **Retrieve Order** endpoint· δεν είναι τα OAuth client credentials. Απαιτούνται μόνο όταν `VIVA_ENABLED=true`, ώστε το fallback reconciliation να μπορεί να βρει το transaction ID για pending payment order. Αν λείπουν, η εντολή τερματίζει με failure και γράφει ασφαλές operational event, χωρίς να αλλάξει παραγγελίες.

## Routes

| Method | Route | Χρήση |
|---|---|---|
| `GET` | `/payments/viva/{order}/start` | Δημιουργεί/επαναχρησιμοποιεί Viva payment order και κάνει redirect στο Smart Checkout. Απαιτεί το ίδιο customer session και το opaque order token. |
| `GET` | `/payments/viva/success` | Return URL επιτυχίας. Δεν χαρακτηρίζει την παραγγελία ως πληρωμένη. |
| `GET` | `/payments/viva/failure` | Return URL αποτυχίας/ακύρωσης. Δεν αλλάζει payment state. |
| `POST` | `/payments/viva/webhook` | Webhook για `Transaction Payment Created` (`EventTypeId=1796`). |

Webhook URL για μελλοντική δήλωση στη Viva:

```text
https://YOUR-DOMAIN/payments/viva/webhook
```

Τα Success/Failure URLs του Viva payment source πρέπει αντίστοιχα να δείχνουν στα `/payments/viva/success` και `/payments/viva/failure`.

## Ασφάλεια πληρωμής

Το return του browser δεν αποτελεί απόδειξη πληρωμής. Το webhook χρησιμοποιείται μόνο ως trigger: ο server ανακτά τη συναλλαγή από το OAuth-authenticated Viva Retrieve Transaction API και ελέγχει `orderCode`, status `F`, EUR currency code `978` και ακριβές server-calculated order total. Τα `viva_order_code` και `viva_transaction_id` είναι unique και η επεξεργασία duplicate webhook είναι idempotent. Pending Viva orders δεν περνούν σε kitchen/courier πριν επιβεβαιωθούν.

Το webhook δεν έχει πλέον fixed `throttle:60,1`: η Viva μπορεί να κάνει retries/παράλληλες παραδόσεις και το server-to-server verification παραμένει η ουσιαστική προστασία. Ακατάλληλα EventType, OrderCode ή TransactionId απορρίπτονται πριν από κλήση στη Viva.

## Missed-webhook reconciliation

Το `viva:reconcile-pending-payments` εκτελείται από Laravel scheduler κάθε 5 λεπτά. Εξετάζει μόνο Viva orders που είναι ακόμη `pending`, έχουν Viva order code, δεν είναι `completed` ή `cancelled`, και δημιουργήθηκαν πριν από τουλάχιστον 5 αλλά όχι πάνω από 90 λεπτά.

Για κάθε candidate ανακτά το transaction ID από Retrieve Order και κατόπιν περνά το Retrieve Transaction response από την ίδια payment-confirmation λογική με το webhook: order code, `F`, `978`, exact cents amount, database transaction, `lockForUpdate()` και unique transaction ID. Περιπτώσεις mismatch, malformed IDs ή προσωρινά errors γράφονται στο `payments` log χωρίς PII για manual review. Η reconciliation δεν μεταβάλλει terminal orders· ένα late webhook συνεχίζει να δίνει το ήδη υπάρχον critical refund/manual-review signal για cancelled order.

Στον production cron απαιτείται το Laravel scheduler, συνήθως:

```cron
* * * * * cd /path/to/coffee-delivery && php artisan schedule:run >> /dev/null 2>&1
```

## Τι απομένει για πραγματικό sandbox test

1. Δημιουργία demo Smart Checkout credentials και online payment source, με τα σωστά return URLs και source code.
2. Ρύθμιση του Viva webhook `Transaction Payment Created` προς το παραπάνω URL. Η Viva απαιτεί ξεχωριστό `GET` verification handshake με Merchant ID/API Key για merchant-level registration· αυτό δεν προστέθηκε σκόπιμα, επειδή δεν ανήκει στα πέντε Smart Checkout env variables του scaffold. Πρέπει να ολοκληρωθεί κατά το sandbox onboarding πριν ενεργοποιηθεί το webhook στο Viva dashboard.
3. Επιβεβαίωση HTTPS/TLS, των τρεχόντων Viva webhook IP allowlists στο firewall/CDN και των success/failure URLs.
4. HTTP sandbox test με Viva test card και έλεγχος create order → redirect → webhook → `paid_at`.
5. Μόνο στο sandbox environment, αλλαγή σε `VIVA_ENABLED=true`. Production credentials και `VIVA_ENVIRONMENT=production` μπαίνουν αφού ολοκληρωθεί επιτυχώς όλη η sandbox ροή.

Τα automated tests χρησιμοποιούν Laravel HTTP fakes και δεν πραγματοποιούν πραγματικές κλήσεις στη Viva.
