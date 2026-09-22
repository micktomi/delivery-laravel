# Viva.com Smart Checkout scaffold

Η ενσωμάτωση είναι scaffold και παραμένει απενεργοποιημένη από προεπιλογή. Με `VIVA_ENABLED=false` η Viva δεν εμφανίζεται στο checkout και δεν μπορεί να ξεκινήσει νέα online πληρωμή. Οι υπάρχουσες πληρωμές `cash` και `pos_courier` διατηρούν την ίδια ροή.

## Ρυθμίσεις

```env
VIVA_ENABLED=false
VIVA_CLIENT_ID=
VIVA_CLIENT_SECRET=
VIVA_SOURCE_CODE=
VIVA_ENVIRONMENT=demo
VIVA_WEBHOOK_VERIFICATION_KEY=
VIVA_RECONCILIATION_MERCHANT_ID=
VIVA_RECONCILIATION_API_KEY=
```

Το `VIVA_ENVIRONMENT` δέχεται `demo` ή `production` (`live` γίνεται επίσης δεκτό ως production alias). Μετά την αλλαγή env/config απαιτείται το συνήθες `php artisan config:clear` ή rebuild του production config cache. Πριν από δοκιμή χρειάζεται και εκτέλεση του νέου migration.

### Τι ακριβώς σταματά το `VIVA_ENABLED=false`

Ο διακόπτης σταματά **μόνο νέες πληρωμές**, όχι πληρωμές που βρίσκονται ήδη σε εξέλιξη. Με `VIVA_ENABLED=false`:

- Η Viva δεν εμφανίζεται στο checkout και το `/payments/viva/{order}/start` επιστρέφει 404, οπότε δεν δημιουργείται ούτε επαναχρησιμοποιείται payment order.
- Το webhook **παραμένει ενεργό**. Αν ένας πελάτης βρισκόταν στη σελίδα της Viva τη στιγμή που έκλεισε ο διακόπτης, η καθυστερημένη επιβεβαίωση προσγειώνεται κανονικά: ο server επαληθεύει τη συναλλαγή και μαρκάρει την παραγγελία `paid`. Αυτό προϋποθέτει ότι τα credentials παραμένουν στο `.env` — αν τα αφαιρέσεις, τα χρήματα κινούνται χωρίς τοπική εγγραφή.
- Το GET verification handshake συνεχίζει να απαντά, ώστε να μη χαλάσει η εγγραφή του webhook στο Viva dashboard.
- **Ασυμμετρία που πρέπει να ξέρεις:** το `viva:reconcile-pending-payments` αντίθετα βγαίνει αμέσως όταν η Viva είναι απενεργοποιημένη. Ένα webhook που *χάθηκε* όσο ο διακόπτης ήταν κλειστός δεν ανακτάται αυτόματα — χρειάζεται προσωρινή επαναφορά σε `VIVA_ENABLED=true` για ένα run, ή χειροκίνητος έλεγχος στο Viva dashboard.

Το συμβόλαιο αυτό κατοχυρώνεται από το `tests/Feature/VivaDisabledSwitchTest.php`.

Τα `VIVA_RECONCILIATION_MERCHANT_ID` και `VIVA_RECONCILIATION_API_KEY` είναι τα Merchant API credentials (Basic auth)· δεν είναι τα OAuth client credentials. Εξυπηρετούν τρία endpoints:

| Endpoint | Χρήση |
|---|---|
| `GET /api/transactions/?ordercode=` | Transaction search — από εδώ βρίσκονται τα candidate transaction IDs μιας pending παραγγελίας. |
| `GET /api/orders/{orderCode}` | Retrieve order — `StateId` 0 Pending, 1 Expired, 2 Canceled, 3 Paid. |
| `DELETE /api/orders/{orderCode}` | Ακύρωση standing payment order πριν την τοπική ακύρωση. |

Απαιτούνται μόνο όταν `VIVA_ENABLED=true`. Αν λείπουν, η εντολή reconciliation τερματίζει με failure και γράφει ασφαλές operational event χωρίς να αλλάξει παραγγελίες — και η ακύρωση μιας pending Viva παραγγελίας από τον admin απορρίπτεται, ώστε να μην ακυρωθεί τοπικά κάτι που παραμένει πληρωτέο στη Viva.

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

Το webhook φέρει per-IP `throttle:300,1`. Το όριο δεν προστατεύει από πλαστές πληρωμές — αυτό το κάνει το server-to-server verification — αλλά από κατανάλωση quota: το endpoint είναι unauthenticated και **κάθε αποδεκτό payload κοστίζει μία OAuth-authenticated κλήση Retrieve Transaction στη Viva**. Όποιος γνωρίζει ένα ζωντανό 16ψήφιο order code (κάθε πελάτης ξέρει το δικό του, είναι στο redirect URL του checkout) θα μπορούσε διαφορετικά να κάψει το quota του λογαριασμού ένα POST τη φορά. Τα 300/λεπτό ανά IP είναι πολύ πάνω από τον πραγματικό ρυθμό παράδοσης και retries της Viva, οπότε το όριο ενεργοποιείται μόνο σε κατάχρηση. Δεν αντικαθιστά το IP allowlist στο firewall/CDN. Ακατάλληλα EventType, OrderCode ή TransactionId απορρίπτονται πριν από κλήση στη Viva.

## Missed-webhook reconciliation

Το `viva:reconcile-pending-payments` εκτελείται από Laravel scheduler κάθε 5 λεπτά. Εξετάζει Viva orders που είναι ακόμη `pending`, έχουν Viva order code, δεν είναι `completed`, και δημιουργήθηκαν πριν από τουλάχιστον 5 λεπτά. **Δεν υπάρχει άνω χρονικό όριο** — μια πληρωμή ανακτάται ακόμη και μετά από πολυήμερο outage. Οι `cancelled` παραγγελίες συμπεριλαμβάνονται σκόπιμα, ώστε χρήματα που κινήθηκαν αργά να εντοπίζονται.

Για κάθε candidate ανακτά τα candidate transaction IDs από το transaction search (`GET /api/transactions/?ordercode=`) και κατόπιν περνά κάθε Retrieve Transaction response από την ίδια payment-confirmation λογική με το webhook: order code, `F`, `978`, exact cents amount, database transaction, `lockForUpdate()` και unique transaction ID. Περιπτώσεις mismatch, malformed IDs ή προσωρινά errors γράφονται στο `payments` log χωρίς PII για manual review. Η reconciliation δεν μεταβάλλει terminal orders· ένα late webhook συνεχίζει να δίνει το ήδη υπάρχον critical refund/manual-review signal για cancelled order.

### Λήξη εγκαταλελειμμένων checkouts

Όταν το search δεν δώσει πληρωτέα συναλλαγή, η reconciliation ρωτά το `GET /api/orders/{orderCode}`. Αν το `StateId` είναι 1 (Expired) ή 2 (Canceled), η παραγγελία περνά στο τερματικό `payment_status = 'expired'`: φεύγει από το candidate set και από τον μετρητή «εκκρεμείς πληρωμές» του admin. Χωρίς αυτό, κάθε πελάτης που άνοιγε το Smart Checkout και δεν πλήρωνε άφηνε μια παραγγελία που ξαναρωτιόταν κάθε 5 λεπτά επ' άπειρον.

`StateId` 0 (Pending) και 3 (Paid) δεν αλλάζουν τίποτα. Οποιαδήποτε μη αξιοποιήσιμη απάντηση — transport failure, HTTP error, αταίριαστο order code, malformed `StateId` — αφήνει την παραγγελία `pending` για το επόμενο run. Το `status` της παραγγελίας δεν μεταβάλλεται ποτέ από αυτή τη διαδρομή. Μια ληγμένη παραγγελία δεν μπορεί πλέον να ξεκινήσει checkout και η σελίδα tracking δείχνει μήνυμα λήξης αντί για κουμπί πληρωμής.

### Άγνωστο αποτέλεσμα δημιουργίας payment order

Αν χαθεί η απόκριση του `POST /checkout/v2/orders` (η Viva μπορεί να δημιούργησε ή να μη δημιούργησε payment order), η παραγγελία παίρνει `payment_status = 'payment_order_unknown'`. Σε αυτή την κατάσταση: δεν γίνεται δεύτερη προσπάθεια πληρωμής, δεν επιτρέπεται ακύρωση από τον admin, και **η reconciliation δεν την πιάνει** — δεν υπάρχει order code για να ψάξει. Εμφανίζεται στον μετρητή «ασυνέπειες πληρωμών» του admin και λύνεται χειροκίνητα μετά από έλεγχο στο Viva dashboard:

```bash
php artisan viva:resolve-ambiguous-payment {order} --order-code=0000000000000000
php artisan viva:resolve-ambiguous-payment {order} --not-created
```

Η πρώτη μορφή συνδέει την παραγγελία με payment order που βρέθηκε στο dashboard και την επαναφέρει σε `pending`, οπότε ξαναμπαίνει στην κανονική reconciliation. Η δεύτερη επιβεβαιώνει ότι δεν δημιουργήθηκε τίποτα και επιτρέπει μία καθαρή νέα προσπάθεια πληρωμής.

Στον production cron απαιτείται το Laravel scheduler, συνήθως:

```cron
* * * * * cd /path/to/coffee-delivery && php artisan schedule:run >> /dev/null 2>&1
```

## Τι απομένει για πραγματικό sandbox test

1. Δημιουργία demo Smart Checkout credentials και online payment source, με τα σωστά return URLs και source code.
2. Ρύθμιση του Viva webhook `Transaction Payment Created` προς το παραπάνω URL. Το `GET` verification handshake που ζητά η Viva υλοποιείται ήδη στο ίδιο URL (`viva.webhook.verify`) και επιστρέφει το `VIVA_WEBHOOK_VERIFICATION_KEY` ως `{"Key": "..."}` — αρκεί να έχει οριστεί το env variable πριν πατηθεί «Verify» στο Viva dashboard.
3. Επιβεβαίωση HTTPS/TLS, των τρεχόντων Viva webhook IP allowlists στο firewall/CDN και των success/failure URLs.
4. HTTP sandbox test με Viva test card και έλεγχος create order → redirect → webhook → `paid_at`.
5. Μόνο στο sandbox environment, αλλαγή σε `VIVA_ENABLED=true`. Production credentials και `VIVA_ENVIRONMENT=production` μπαίνουν αφού ολοκληρωθεί επιτυχώς όλη η sandbox ροή.

Τα automated tests χρησιμοποιούν Laravel HTTP fakes και δεν πραγματοποιούν πραγματικές κλήσεις στη Viva.
