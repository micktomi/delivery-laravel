# Viva.com Smart Checkout scaffold

Η ενσωμάτωση είναι scaffold και παραμένει απενεργοποιημένη από προεπιλογή. Με `VIVA_ENABLED=false` η Viva δεν εμφανίζεται στο checkout και δεν μπορεί να ξεκινήσει νέα online πληρωμή. Οι υπάρχουσες πληρωμές `cash` και `pos_courier` διατηρούν την ίδια ροή.

## Ρυθμίσεις

```env
VIVA_ENABLED=false
VIVA_CLIENT_ID=
VIVA_CLIENT_SECRET=
VIVA_SOURCE_CODE=
VIVA_ENVIRONMENT=demo
```

Το `VIVA_ENVIRONMENT` δέχεται `demo` ή `production` (`live` γίνεται επίσης δεκτό ως production alias). Μετά την αλλαγή env/config απαιτείται το συνήθες `php artisan config:clear` ή rebuild του production config cache. Πριν από δοκιμή χρειάζεται και εκτέλεση του νέου migration.

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

## Τι απομένει για πραγματικό sandbox test

1. Δημιουργία demo Smart Checkout credentials και online payment source, με τα σωστά return URLs και source code.
2. Ρύθμιση του Viva webhook `Transaction Payment Created` προς το παραπάνω URL. Η Viva απαιτεί ξεχωριστό `GET` verification handshake με Merchant ID/API Key για merchant-level registration· αυτό δεν προστέθηκε σκόπιμα, επειδή δεν ανήκει στα πέντε Smart Checkout env variables του scaffold. Πρέπει να ολοκληρωθεί κατά το sandbox onboarding πριν ενεργοποιηθεί το webhook στο Viva dashboard.
3. Επιβεβαίωση HTTPS/TLS, των τρεχόντων Viva webhook IP allowlists στο firewall/CDN και των success/failure URLs.
4. HTTP sandbox test με Viva test card και έλεγχος create order → redirect → webhook → `paid_at`.
5. Μόνο στο sandbox environment, αλλαγή σε `VIVA_ENABLED=true`. Production credentials και `VIVA_ENVIRONMENT=production` μπαίνουν αφού ολοκληρωθεί επιτυχώς όλη η sandbox ροή.

Τα automated tests χρησιμοποιούν Laravel HTTP fakes και δεν πραγματοποιούν πραγματικές κλήσεις στη Viva.
