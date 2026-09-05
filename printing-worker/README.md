# Kitchen print worker

Ένα Rust binary που καταναλώνει το υπάρχον Laravel pull API και εκτυπώνει μόνο jobs τύπου `kitchen`. Δεν απαιτεί υπηρεσία queue, Redis, scheduler αποστολής ή inbound endpoint στο κατάστημα.

## Τρέχον Laravel συμβόλαιο

Πηγή αλήθειας: [controller](../app/Http/Controllers/PrintJobController.php), [δημιουργία snapshot](../app/Actions/CreateKitchenPrintJob.php), [routes](../routes/api.php), [retry](../app/Console/Commands/RetryFailedPrintJobs.php), [migration](../database/migrations/2026_09_05_000001_create_print_jobs_table.php). Η Rust υλοποίηση δεν αλλάζει αυτά τα αρχεία.

- Η μετάβαση `Nea → Preparing` δημιουργεί ένα job μέσα στην ίδια transaction, μετά τους ελέγχους πληρωμής και αναμενόμενης κατάστασης. Unique order ID και κλείδωμα παραγγελίας αποτρέπουν δεύτερο job.
- Snapshot version 1, αποκλειστικά `type: kitchen`. Δεν υπάρχουν aliases, delivery jobs ή αυτόματες επανεκτυπώσεις.
- Περιλαμβάνει UUID, order ID, display number, timestamps, timezone, όνομα πελάτη, είδη/ποσότητες/options και σημειώσεις. Δεν περιλαμβάνει οικονομικά στοιχεία, στοιχεία επικοινωνίας ή διεύθυνση. Κενό items array είναι έγκυρο.
- Όλες οι κλήσεις είναι POST με bearer token, Accept/Content-Type JSON.
- `/api/printing/next`: 204 ή 200 με `{attempt, payload}`. Επιλέγει διαθέσιμο pending job κατά created_at, μετά UUID, με database row lock.
- Claim 60 δευτερολέπτων: το status παραμένει pending, αυξάνεται το attempts και ενημερώνεται το last_attempt_at. Ακριβώς στα 60 δευτερόλεπτα επιτρέπεται νέο claim. Κανένα scheduler ή αυτόματο όριο attempts δεν εμπλέκεται.
- Το attempt είναι έξω από το αμετάβλητο snapshot. Κάθε reclaim ή πρώτο claim μετά από operator retry αυξάνει τον μετρητή. Τα άδεια polls και τα callbacks δεν τον αυξάνουν.
- `/{UUID}/accepted` με `{attempt}`: ο worker δηλώνει μόνιμη αποθήκευση και ανάληψη ευθύνης. Το Laravel θέτει sent/sent_at και καθαρίζει last_error. **Sent σημαίνει durable handoff, όχι επιβεβαίωση χαρτιού.** Ο server εμπιστεύεται τη δήλωση του worker· δεν ελέγχει το τοπικό SQLite ή τον εκτυπωτή.
- `/{UUID}/failed` με attempt και error: μόνο `storage_unavailable`, `invalid_payload`, `unsupported_version`. Δεν αφορά φυσικές βλάβες.
- Η σειρά ελέγχων callback είναι: validation → εύρεση UUID/lock → έλεγχος current fetched attempt → idempotent ίδιο outcome → έλεγχος payload → pending status → lease. Έτσι παλιό/unfetched attempt δίνει 409 πριν από άλλους ελέγχους, ενώ ίδιο resolved outcome/current attempt δίνει 200 και μετά τη λήξη lease ή την αφαίρεση payload, χωρίς αλλαγή timestamps/error.
- Για unresolved pending job, lease ηλικίας >=60s δίνει 409 ακόμη και πριν από reclaim. Αντικρουόμενα outcomes δίνουν 409 με retained payload. Αφαιρεμένο payload δίνει 410 εφόσον δεν προηγήθηκε stale-attempt ή idempotent return. Άγνωστο UUID 404, validation 422.
- Το `printing:retry-failed [UUID]` επαναφέρει μόνο failed jobs με payload σε pending, καθαρίζοντας last_attempt_at/last_error. Διατηρεί UUID, snapshot και attempts. Παλιά callbacks απορρίπτονται και πριν από νέο claim.
- Κοινός limiter `throttle:120,1,printing` και για τις τρεις διαδρομές. Για αυτό το stateless API το κλειδί χρησιμοποιεί prefix printing και route domain/client IP. Το polling ανά 3s καταναλώνει περίπου 20 requests/minute πριν από callbacks. 429 απαιτεί backoff.
- Η retention διαδικασία αφαιρεί payload και κρατά UUID/order association. Διατηρεί sent· τα υπόλοιπα γίνονται failed/payload_expired. Δεν επιστρέφονται πλέον από polling.
- Δεν υπάρχουν push dispatcher αρχεία, command `printing:send`, worker URL setting ή scheduled print dispatch στο τρέχον Laravel source. Το routes/console.php προγραμματίζει payment reconciliation και order retention.

Το [παλαιότερο κείμενο του συμβολαίου](../docs/printing-bridge.md) παραμένει ανέπαφο. Η δήλωση εκεί ότι δεν υπάρχει Rust worker δεν περιγράφει πλέον αυτόν τον φάκελο.

## Αρχιτεκτονική

Ένας σειριακός βρόχος, με synchronous HTTP και μία τοπική SQLite βάση. Το reqwest χρησιμοποιεί εσωτερικά runtime για HTTP· η εφαρμογή δεν έχει δικό της async framework ή ανεξάρτητους workers.

- `contract.rs`: typed parsing, kitchen-only validation, semantic snapshot digest.
- `api.rs`: τα τρία POST, bearer auth, TLS verification, timeouts, response validation.
- `store.rs`: transactional deduplication, persistent acknowledgements, printing lifecycle και operator resolution.
- `worker.rs`: polling/acknowledgements/backoff και απομονωμένα printer errors.
- `receipt.rs`, `printer.rs`: ελληνικό receipt, raster ESC/POS και stdout/TCP/USB έξοδος.
- `config.rs`, `main.rs`: environment, CLI, λειτουργία process.

Μία τοπική βάση σε αξιόπιστο filesystem, με synchronous=FULL, DELETE journal, secure_delete και αποκλειστικό process lock. Το binary δημιουργεί αρχεία με umask 077. Μην τρέχετε διαφορετικές βάσεις/instances για τον ίδιο φυσικό εκτυπωτή και token. Μην διαγράφετε το lock file όσο λειτουργεί το process. Το lock αποτρέπει instances που μοιράζονται την ίδια βάση, όχι ανεξάρτητες εγκαταστάσεις.

## Build και development

Rust >=1.89, C compiler/linker για bundled SQLite και ring. Δεν απαιτείται εγκατεστημένο SQLite server ή OpenSSL. Οι εκδόσεις είναι κλειδωμένες στο Cargo.lock.

```sh
cd coffee-delivery/printing-worker
cargo build --release --locked

export LARAVEL_URL=http://127.0.0.1:8000
export PRINT_WORKER_TOKEN='το-ίδιο-token-με-το-Laravel'
export PRINT_WORKER_DB="$PWD/data/development.sqlite"
export PRINTER_BACKEND=stdout
./target/release/kitchen-print-worker run
```

Το binary διαβάζει **environment**, όχι αυτόματα .env. Υπάρχει ανεξάρτητο .env.example για τον worker.

Το stdout είναι mock backend με πραγματικό polling/acknowledgement/deduplication. **Καταναλώνει κανονικά τα jobs και τα σημειώνει locally completed.** Χρησιμοποιήστε το με development Laravel και ξεχωριστή βάση, όχι ως preview παραγωγικών jobs. Η απόδειξη πηγαίνει στο stdout και περιλαμβάνει προσωπικά δεδομένα· τα διαγνωστικά πάνε στο stderr χωρίς payload ή token.

## Ρυθμίσεις

| Μεταβλητή | Προεπιλογή / σημασία |
|---|---|
| LARAVEL_URL | Υποχρεωτικό base URL εφαρμογής, χωρίς /api/printing. HTTPS, ή HTTP μόνο σε loopback για development. Υποστηρίζει subdirectory. |
| PRINT_WORKER_TOKEN | Υποχρεωτικό, ίδιο με Laravel. |
| PRINT_POLL_SECONDS | 3, ακέραιος 2–15. Το διάστημα μετρά μετά το HTTP exchange. |
| PRINT_WORKER_DB | data/worker.sqlite. Σε service, απόλυτο path σε μόνιμο local δίσκο. |
| PRINT_PAYLOAD_RETENTION_DAYS | 30, 1–3650. Καθαρίζει payloads completed jobs ωριαία, διατηρεί UUID/hash. |
| PRINTER_BACKEND | stdout, escpos-tcp ή escpos-usb. |
| PRINTER_ADDRESS | Για TCP: literal IP:port, π.χ. 192.168.1.50:9100. |
| PRINTER_DEVICE | Για USB: Linux character device, π.χ. /dev/usb/lp0. Δεν δημιουργείται αρχείο αν λείπει. Symlinks απορρίπτονται. |
| PRINTER_FONT | /usr/share/fonts/truetype/dejavu/DejaVuSans.ttf, TTF/OTF με ελληνικά. |
| PRINTER_WIDTH_DOTS | 576, πολλαπλάσιο του 8, 128–832. Συνήθης αρχική τιμή 384 για 58mm· ελέγξτε το πραγματικό printable width. |
| PRINTER_FONT_PX | 28, 16–48. |
| PRINTER_CUT | false. true μόνο εφόσον υπάρχει συμβατός κόφτης. |

HTTP connect timeout 5s, συνολικό request timeout 10s, response cap 1MiB. Redirects δεν ακολουθούνται. Transient transport/5xx errors επαναλαμβάνονται με backoff 6,12,24,48,60s. Το Retry-After (seconds ή HTTP date) είναι κατώτατο όριο, κοινό για polls και callbacks. Τα 429 χωρίς header καθυστερούν τουλάχιστον 60s. Authentication/configuration/protocol failures σταματούν το process με exit 1.

Ανά tick γίνονται έως ένα persisted accepted callback, ένα poll και ένα failure callback αν το νέο payload απορριφθεί. Η εφαρμογή δεν χρησιμοποιεί browser session ή CSRF.

## Deduplication, acknowledgement και restart

1. Το εισερχόμενο UUID είναι primary key. Το snapshot αποθηκεύεται και γίνεται commit **πριν** σταλεί accepted.
2. Το acknowledgement παραμένει pending στο SQLite μέχρι το Laravel να απαντήσει 200 με το αναμενόμενο UUID/status. Χαμένη απάντηση επαναλαμβάνει το ίδιο accepted/current attempt, και μετά από restart.
3. Η φυσική έξοδος ξεκινά μόνο μετά από επιβεβαιωμένο accepted. Νέο attempt για ήδη γνωστό job ενημερώνει μόνο το acknowledgement· δεν ξαναδημιουργεί εκτύπωση. Ίδιο UUID με διαφορετικό snapshot απορρίπτεται με invalid_payload και διατηρείται το αρχικό.
4. Σε 409 το acknowledgement γίνεται blocked. Δεν επαναλαμβάνεται το ίδιο attempt και δεν στέλνεται αντίθετο outcome. Νέο claim με μεγαλύτερο attempt ξεμπλοκάρει μόνο το acknowledgement. Αν το Laravel job είναι failed, χρειάζεται πρώτα Laravel operator retry.
5. 404/410 σταματούν το συγκεκριμένο acknowledgement ως gone. Το job δεν εκτυπώνεται αν δεν είχε προηγουμένως επιβεβαιωθεί η αποδοχή.
6. Πριν από οποιοδήποτε device write, γίνεται durable μετάβαση queued → printing. Επιτυχής write σημαίνει locally completed· σφάλμα σημαίνει uncertain.
7. Restart με printing το μετατρέπει σε uncertain. Δεν υπάρχει αυτόματη επανεκτύπωση ακόμη κι αν η έξοδος είχε προλάβει να ολοκληρωθεί πριν καταγραφεί completed.
8. Render error δημιουργεί held πριν από οποιαδήποτε έξοδο. Οποιοδήποτε held/uncertain job παγώνει την επόμενη φυσική εκτύπωση μέχρι operator resolution, ενώ polling και durable acceptance συνεχίζονται.
9. Printer errors μετά την αποδοχή μένουν αποκλειστικά τοπικά. Δεν υποβαθμίζουν ποτέ Laravel sent και δεν καλούν /failed.

Τα failed callbacks πριν από durable acceptance είναι best effort: αν χαθεί η απάντηση, είτε το Laravel έχει ήδη failed job είτε η lease λήγει και το ξαναδίνει. Δεν δημιουργείται τοπικό accepted job σε αποτυχία αποθήκευσης. Ο worker σταματά μετά από storage failure ώστε να αντιμετωπιστεί η αιτία.

Τα UUID/digests δεν διαγράφονται αυτόματα. Completed payloads καθαρίζονται μετά τη ρυθμισμένη retention, χωρίς να χάνεται deduplication. Pending/held/uncertain/gone payloads διατηρούνται για επίλυση· χρειάζονται λειτουργική παρακολούθηση και πολιτική διατήρησης. Το digest διατηρεί τις τιμές JSON και τη σειρά arrays, αγνοώντας whitespace/object-key order. Δεν αποτελεί κρυπτογράφηση προσωπικών δεδομένων.

Κρατήστε το ίδιο SQLite κατά τις αναβαθμίσεις. Για backup σταματήστε το service και αντιγράψτε τη βάση. Επαναφορά παλιού τοπικού backup μπορεί να χάσει νεότερα completed IDs: απαιτεί συμφωνία με τον χειριστή πριν συνεχιστεί η εκτύπωση. Το HTTP και η επιτυχής write δεν εγγυώνται exactly-once φυσική εκτύπωση.

## Χειριστής

Σταματήστε πρώτα το service ώστε να απελευθερωθεί το process lock. Οι εντολές status/resolve χρειάζονται μόνο PRINT_WORKER_DB.

```sh
export PRINT_WORKER_DB=/var/lib/kitchen-print-worker/worker.sqlite
./kitchen-print-worker status
./kitchen-print-worker resolve UUID completed
# Μόνο μετά από έλεγχο ότι χρειάζεται επανάληψη:
./kitchen-print-worker resolve UUID retry
```

Το completed κλείνει held/uncertain εργασία χωρίς νέα έξοδο. Το retry επιτρέπεται μόνο για held/uncertain με retained payload και επιβεβαιωμένο acknowledgement. Δεν επιτρέπεται επανεκτύπωση completed jobs. Ελέγξτε πρώτα το χαρτί και τη συσκευή: retry uncertain job μπορεί να δημιουργήσει δεύτερη απόδειξη αν η πρώτη είχε ήδη εκτυπωθεί.

## Printer backend και service

Το escpos-tcp γράφει raw bytes στο socket. Το escpos-usb χρησιμοποιεί Linux usblp, nonblocking device I/O και poll. Και τα δύο έχουν συνολικό deadline εξόδου 10s. Σφάλμα ακόμη και πριν από το πρώτο byte αντιμετωπίζεται συντηρητικά ως uncertain.

Το receipt αποδίδεται σε μονόχρωμο raster με fontdue. Τα ελληνικά δεν αποστέλλονται ως text code page. Τα customer fields καθαρίζονται από control characters πριν τη διάταξη. Μη υποστηριζόμενο glyph ή υπερβολικό ύψος (>32768 dots) οδηγεί σε held, χωρίς σιωπηλή αλλοίωση της απόδειξης.

Η έξοδος χρησιμοποιεί ESC @, GS v 0 σε λωρίδες έως 128 γραμμών, feed 4 γραμμών και προαιρετικό GS V partial cut. Το **GS v 0 είναι συγκεκριμένο compatibility profile**, όχι υπόσχεση υποστήριξης όλων των ESC/POS εκτυπωτών. Η Epson το χαρακτηρίζει obsolete και τεκμηριώνει υποστήριξη μόνο σε ορισμένα μοντέλα: [επίσημη αναφορά GS v 0](https://download4.epson.biz/sec_pubs/pos/reference_en/escpos/gs_lv_0.html). Απαιτείται επιβεβαίωση για το μοντέλο του καταστήματος.

Υπάρχει υπόδειγμα [systemd unit](deploy/kitchen-print-worker.service), χωρίς αυτόματη εγκατάσταση. Προβλέπει:

- binary στο /opt/kitchen-print-worker/kitchen-print-worker,
- service account print-worker και, για USB, δικαιώματα lp/usblp,
- environment στο /etc/kitchen-print-worker.env με περιορισμένη πρόσβαση,
- μόνιμη βάση στο /var/lib/kitchen-print-worker/worker.sqlite,
- restart σε process failure, έως 3 αποτυχίες μέσα σε 5 λεπτά.

Στην παραγωγή επιλέξτε ρητά escpos-tcp ή escpos-usb. SIGTERM/SIGINT τερματίζουν το process· αν πετύχουν device write, η εγγραφή printing ανακτάται ως uncertain. Δεν εγκαθίσταται custom supervisor ή πρόσθετη υπηρεσία.

## Dependencies

| Crate | Χρήση |
|---|---|
| reqwest 0.12, rustls TLS | Blocking HTTPS, JSON requests, deadlines. |
| serde / serde_json 1 | Typed payload και canonical object serialization. |
| rusqlite 0.37, bundled | Τοπικές durable transactions χωρίς SQLite service. |
| sha2 0.10 | SHA-256 snapshot digest για tombstones. |
| uuid 1 | UUID parsing και σταθερά IDs. |
| chrono 0.4 / chrono-tz 0.10 | ISO timestamps και τοπική ώρα με DST. |
| fontdue 0.9 | Raster ελληνικών από TTF/OTF. |
| libc 0.2 | Linux nonblocking USB/poll και Unix umask. |
| anyhow 1 | Επιστροφή λειτουργικών σφαλμάτων. |
| tempfile 3, μόνο tests | Απομονωμένες βάσεις και προσωρινά αρχεία. |

## Έλεγχοι

```sh
cargo fmt --check
cargo test --locked
cargo clippy --locked --all-targets -- -D warnings
cargo build --release --locked
```

Τα raster tests απαιτούν DejaVu Sans ή PRINT_TEST_FONT με ισοδύναμη κάλυψη ελληνικών. Δεν παραλείπονται σιωπηλά αν λείπει γραμματοσειρά. Προαιρετικά, PRINT_TEST_PREVIEW=/tmp/receipt.pbm παράγει εικόνα από τα πραγματικά ESC/POS raster bytes του test.

Οι tests καλύπτουν parsing, kitchen-only τύπο, UTF-8/ώρα/options/notes, raster framing, semantic deduplication, crash/restart, χαμένα acknowledgements, νέα/stale attempts, 409, 404/410, 429/backoff, auth/redirect/invalid responses, SQLite failure και printer/render failure. Τα HTTP tests χρησιμοποιούν πραγματικό reqwest client και loopback server.

Ο επανέλεγχος του ανέπαφου Laravel source έδωσε:
- printing bridge: **17 tests, 448 assertions**,
- πλήρης Laravel suite: **410 tests, 2378 assertions**.

## Εκκρεμεί επιβεβαίωση hardware

Στο πραγματικό μοντέλο ελέγξτε command compatibility, πραγματικό πλάτος, ελληνικά/τόνους/αναδίπλωση, font size, feed/cut, μεγάλα receipts και σταθερότητα USB/TCP. Δοκιμάστε paper-out, αποσύνδεση, διακοπή ρεύματος και crash κατά την εκτύπωση μαζί με τη διαδικασία operator resolution.

Locally completed σημαίνει ότι ολοκληρώθηκε η write στο backend. Δεν υπάρχει printer status/completion protocol ή επιβεβαίωση ότι βγήκε χαρτί. Αυτή η έκδοση δεν ενσωματώνει άλλους τύπους job, αυτόματες επανεκτυπώσεις ή monitoring φυσικής ολοκλήρωσης.
