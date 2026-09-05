use crate::{
    api::{ApiError, HttpApi, ReportResult, Transport},
    config,
    contract::{self, FailureCode, Payload},
    printer::Printer,
    receipt::{self, Rasterizer},
    store::{Ack, Ingest, Store},
    worker::Worker,
};
use anyhow::Result;
use serde_json::{Value, json};
use std::{
    collections::VecDeque,
    io::{self, Read, Write},
    net::TcpListener,
    path::Path,
    thread,
    time::Duration,
};
use tempfile::TempDir;

const ID: &str = "c44171d8-9fae-4d7c-8749-cec268e34b27";
fn fixture() -> Value {
    serde_json::from_str(include_str!("../tests/fixtures/kitchen.json")).unwrap()
}
fn delivery(attempt: i64) -> contract::Delivery {
    let mut value = fixture();
    value["attempt"] = json!(attempt);
    contract::parse(value).unwrap()
}
fn store(dir: &TempDir) -> Store {
    Store::open(&dir.path().join("worker.sqlite")).unwrap()
}
fn ack(attempt: i64) -> Ack {
    Ack {
        id: ID.into(),
        attempt,
    }
}
fn accepted(store: &mut Store) {
    store.ingest(&delivery(1)).unwrap();
    store.resolve_ack(&ack(1), "sent").unwrap();
}
#[derive(Default)]
struct FakeApi {
    polls: VecDeque<Result<Option<Value>, ApiError>>,
    reports: VecDeque<Result<ReportResult, ApiError>>,
    calls: Vec<(String, i64, Option<FailureCode>)>,
    poll_count: usize,
}
impl Transport for FakeApi {
    fn poll(&mut self) -> Result<Option<Value>, ApiError> {
        self.poll_count += 1;
        self.polls.pop_front().unwrap_or(Ok(None))
    }
    fn report(
        &mut self,
        id: &str,
        attempt: i64,
        failure: Option<FailureCode>,
    ) -> Result<ReportResult, ApiError> {
        self.calls.push((id.into(), attempt, failure));
        self.reports
            .pop_front()
            .unwrap_or(Ok(ReportResult::Resolved))
    }
}
#[derive(Default)]
struct FakePrinter {
    writes: usize,
    fail_write: bool,
    fail_render: bool,
}
impl Printer for FakePrinter {
    fn prepare(&self, payload: &Payload) -> Result<Vec<u8>> {
        if self.fail_render {
            anyhow::bail!("mock unsupported glyph");
        }
        Ok(receipt::text(payload).into_bytes())
    }
    fn write(&mut self, _: &[u8]) -> io::Result<()> {
        self.writes += 1;
        if self.fail_write {
            Err(io::ErrorKind::BrokenPipe.into())
        } else {
            Ok(())
        }
    }
}

#[test]
fn parses_current_kitchen_contract() {
    let d = delivery(1);
    assert_eq!(d.payload.kind, "kitchen");
    assert_eq!(d.payload.version, 1);
    assert_eq!(d.payload.items[0].quantity, 2);
    assert_eq!(d.payload.customer.name, "Μαρία");
    assert_eq!(d.payload.items[0].options[0].value, "Σκέτος");
}
#[test]
fn empty_items_and_nullable_notes_are_valid() {
    let mut v = fixture();
    v["payload"]["items"] = json!([]);
    v["payload"]["notes"] = Value::Null;
    let d = contract::parse(v).unwrap();
    assert!(d.payload.items.is_empty());
    assert!(receipt::text(&d.payload).contains("Χωρίς καταχωρημένα είδη"));
}
#[test]
fn rejects_every_non_kitchen_type_without_aliases() {
    for kind in ["delivery", "kitchen_receipt", "Kitchen", "receipt", ""] {
        let mut v = fixture();
        v["payload"]["type"] = json!(kind);
        assert_eq!(
            contract::parse(v).err().unwrap().code,
            FailureCode::InvalidPayload
        );
    }
}
#[test]
fn distinguishes_unsupported_version_from_malformed_payload() {
    let mut v = fixture();
    v["payload"]["version"] = json!(2);
    assert_eq!(
        contract::parse(v).err().unwrap().code,
        FailureCode::UnsupportedVersion
    );
    let mut v = fixture();
    v["payload"]["version"] = json!("1");
    assert_eq!(
        contract::parse(v).err().unwrap().code,
        FailureCode::InvalidPayload
    );
}
#[test]
fn rejects_invalid_identity_attempt_dates_and_quantities() {
    for attempt in [json!(0), json!(-1), json!(1.5), json!("1"), Value::Null] {
        let mut v = fixture();
        v["attempt"] = attempt;
        assert!(contract::parse(v).err().unwrap().identity.is_none());
    }
    for field in ["print_job_id", "placed_at", "accepted_at", "timezone"] {
        let mut v = fixture();
        v["payload"][field] = json!("invalid");
        assert!(contract::parse(v).is_err());
    }
    for quantity in [json!(-2), json!(2.5), json!("2")] {
        let mut v = fixture();
        v["payload"]["items"][0]["quantity"] = quantity;
        assert!(contract::parse(v).is_err());
    }
}
#[test]
fn digest_is_semantic_and_attempt_is_not_part_of_snapshot() {
    let pretty = serde_json::to_string_pretty(&fixture()).unwrap();
    let reordered = pretty.replace("\"version\": 1,", "").replace(
        "\"type\": \"kitchen\"",
        "\"type\": \"kitchen\", \"version\": 1",
    );
    assert_eq!(
        contract::parse(serde_json::from_str(&reordered).unwrap())
            .unwrap()
            .digest,
        delivery(2).digest
    );
    let mut changed = fixture();
    changed["payload"]["customer"]["name"] = json!("Άλλη");
    assert_ne!(contract::parse(changed).unwrap().digest, delivery(1).digest);
}
#[test]
fn receipt_renders_greek_options_notes_and_local_time() {
    let text = receipt::text(&delivery(1).payload);
    assert!(text.starts_with("ΚΟΥΖΙΝΑ\nΠΑΡΑΓΓΕΛΙΑ #007\n"));
    assert!(text.contains("05/09/2026 12:30"));
    assert!(text.contains("Αποδοχή: 12:31"));
    assert!(text.contains("2 x Καφές\n  Ζάχαρη: Σκέτος\n  Σημείωση: Λίγος πάγος"));
    assert!(text.contains("ΣΗΜΕΙΩΣΕΙΣ: Χωρίς καλαμάκι"));
    assert!(!text.contains("€"));
}
#[test]
fn receipt_sanitizes_control_and_direction_override_characters() {
    let mut d = delivery(1);
    d.payload.notes = Some("\x1b\x1d\0\r\n\u{202e}abc".into());
    let text = receipt::text(&d.payload);
    assert!(!text.contains(['\x1b', '\x1d', '\0', '\r', '\u{202e}']));
    assert!(text.contains("ΣΗΜΕΙΩΣΕΙΣ:       abc"));
}
#[test]
fn raster_is_bounded_nonempty_and_contains_only_framed_bitmap_commands() {
    let font = std::env::var("PRINT_TEST_FONT")
        .unwrap_or_else(|_| "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf".into());
    let renderer = Rasterizer::new(Path::new(&font), 384, 28.0, false).unwrap();
    let bytes = renderer
        .render(&receipt::text(&delivery(1).payload))
        .unwrap();
    assert_eq!(&bytes[..2], &[0x1b, b'@']);
    let mut offset = 2;
    let mut raster = Vec::new();
    let mut height = 0;
    while bytes[offset] == 0x1d {
        assert_eq!(&bytes[offset..offset + 4], &[0x1d, b'v', b'0', 0]);
        let width = u16::from_le_bytes([bytes[offset + 4], bytes[offset + 5]]) as usize;
        let rows = u16::from_le_bytes([bytes[offset + 6], bytes[offset + 7]]) as usize;
        assert_eq!(width, 48);
        assert!((1..=128).contains(&rows));
        raster.extend_from_slice(&bytes[offset + 8..offset + 8 + width * rows]);
        height += rows;
        offset += 8 + width * rows;
    }
    assert_eq!(&bytes[offset..], &[0x1b, b'd', 4]);
    assert!(raster.iter().any(|b| *b != 0));
    assert!(height > 300);
    if let Ok(path) = std::env::var("PRINT_TEST_PREVIEW") {
        let mut image = format!("P4\n384 {height}\n").into_bytes();
        image.extend(raster);
        std::fs::write(path, image).unwrap();
    }
}
#[test]
fn missing_font_and_unsupported_glyph_fail_before_output() {
    assert!(Rasterizer::new(Path::new("/no-such-worker-font"), 576, 28.0, false).is_err());
    let font = std::env::var("PRINT_TEST_FONT")
        .unwrap_or_else(|_| "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf".into());
    assert!(
        Rasterizer::new(Path::new(&font), 384, 28.0, false)
            .unwrap()
            .render("\u{10ffff}")
            .is_err()
    );
}
#[test]
fn durable_storage_precedes_acceptance_and_output() {
    let dir = TempDir::new().unwrap();
    let api = FakeApi {
        polls: VecDeque::from([Ok(Some(fixture()))]),
        ..Default::default()
    };
    let mut worker = Worker::new(store(&dir), api, FakePrinter::default());
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    assert!(worker.api.calls.is_empty());
    assert_eq!(worker.printer.writes, 0);
    assert!(worker.store.status().unwrap()[0].contains("ack=pending output=queued"));
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.api.calls, vec![(ID.into(), 1, None)]);
    assert_eq!(worker.printer.writes, 1);
}
#[test]
fn restart_resumes_pending_ack_then_prints_once() {
    let dir = TempDir::new().unwrap();
    {
        let mut s = store(&dir);
        s.ingest(&delivery(1)).unwrap();
    }
    let mut worker = Worker::new(store(&dir), FakeApi::default(), FakePrinter::default());
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.api.calls[0].1, 1);
    assert_eq!(worker.printer.writes, 1);
}
#[test]
fn completed_job_is_not_reprinted_after_restart_or_new_attempt() {
    let dir = TempDir::new().unwrap();
    {
        let mut s = store(&dir);
        accepted(&mut s);
        s.begin_print(ID).unwrap();
        s.finish_print(ID, true).unwrap();
    }
    let mut worker = Worker::new(store(&dir), FakeApi::default(), FakePrinter::default());
    worker.store.ingest(&delivery(2)).unwrap();
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.api.calls, vec![(ID.into(), 2, None)]);
    assert_eq!(worker.printer.writes, 0);
}
#[test]
fn same_uuid_with_changed_payload_is_rejected_and_original_survives() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    s.ingest(&delivery(1)).unwrap();
    let mut changed = fixture();
    changed["payload"]["notes"] = json!("Changed");
    changed["attempt"] = json!(2);
    assert_eq!(
        s.ingest(&contract::parse(changed).unwrap()).unwrap(),
        Ingest::Mismatch
    );
    assert_eq!(s.next_ack().unwrap().unwrap().attempt, 1);
}
#[test]
fn stale_deliveries_and_stale_local_ack_results_cannot_mutate_current_attempt() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    s.ingest(&delivery(2)).unwrap();
    assert_eq!(s.ingest(&delivery(1)).unwrap(), Ingest::Stale);
    s.resolve_ack(&ack(1), "sent").unwrap();
    assert_eq!(s.next_ack().unwrap().unwrap().attempt, 2);
    assert!(s.next_print().unwrap().is_none());
}
#[test]
fn conflict_blocks_same_attempt_until_new_poll_claim() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    s.ingest(&delivery(1)).unwrap();
    let api = FakeApi {
        reports: VecDeque::from([Ok(ReportResult::Conflict)]),
        ..Default::default()
    };
    let mut worker = Worker::new(s, api, FakePrinter::default());
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    worker.store.ingest(&delivery(1)).unwrap();
    worker.network_tick().unwrap();
    assert_eq!(worker.api.calls.len(), 1);
    assert_eq!(worker.printer.writes, 0);
    worker.store.ingest(&delivery(2)).unwrap();
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    assert_eq!(
        worker.api.calls,
        vec![(ID.into(), 1, None), (ID.into(), 2, None)]
    );
    assert_eq!(worker.printer.writes, 1);
}
#[test]
fn lost_ack_response_retries_identical_attempt_without_printing_early() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    s.ingest(&delivery(1)).unwrap();
    let api = FakeApi {
        reports: VecDeque::from([Err(ApiError::Retry(None)), Ok(ReportResult::Resolved)]),
        ..Default::default()
    };
    let mut worker = Worker::new(s, api, FakePrinter::default());
    assert!(worker.network_tick().unwrap().is_some());
    worker.print_tick().unwrap();
    assert_eq!(worker.printer.writes, 0);
    assert_eq!(worker.api.poll_count, 0);
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.api.calls[0], worker.api.calls[1]);
    assert_eq!(worker.printer.writes, 1);
}
#[test]
fn crash_during_output_is_uncertain_and_blocks_automatic_replay() {
    let dir = TempDir::new().unwrap();
    {
        let mut s = store(&dir);
        accepted(&mut s);
        s.begin_print(ID).unwrap();
    }
    let mut worker = Worker::new(store(&dir), FakeApi::default(), FakePrinter::default());
    worker.store.ingest(&delivery(2)).unwrap();
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    assert!(worker.store.status().unwrap()[0].contains("output=uncertain"));
    assert_eq!(worker.printer.writes, 0);
    worker
        .store
        .reconcile(ID.parse().unwrap(), "completed")
        .unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.printer.writes, 0);
}
#[test]
fn printer_error_never_reports_laravel_failure_and_requires_operator_retry() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    accepted(&mut s);
    let mut worker = Worker::new(
        s,
        FakeApi::default(),
        FakePrinter {
            fail_write: true,
            ..Default::default()
        },
    );
    worker.print_tick().unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.printer.writes, 1);
    assert!(worker.api.calls.is_empty());
    assert!(worker.store.status().unwrap()[0].contains("ack=sent output=uncertain"));
    worker
        .store
        .reconcile(ID.parse().unwrap(), "retry")
        .unwrap();
    worker.printer.fail_write = false;
    worker.print_tick().unwrap();
    assert_eq!(worker.printer.writes, 2);
}
#[test]
fn rendering_error_holds_job_before_any_device_write() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    accepted(&mut s);
    let mut worker = Worker::new(
        s,
        FakeApi::default(),
        FakePrinter {
            fail_render: true,
            ..Default::default()
        },
    );
    worker.print_tick().unwrap();
    assert_eq!(worker.printer.writes, 0);
    assert!(worker.api.calls.is_empty());
    assert!(worker.store.status().unwrap()[0].contains("output=held"));
}
#[test]
fn gone_ack_is_not_retried_or_printed() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    s.ingest(&delivery(1)).unwrap();
    let api = FakeApi {
        reports: VecDeque::from([Ok(ReportResult::Gone)]),
        ..Default::default()
    };
    let mut worker = Worker::new(s, api, FakePrinter::default());
    worker.network_tick().unwrap();
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.api.calls.len(), 1);
    assert_eq!(worker.printer.writes, 0);
    assert!(worker.store.status().unwrap()[0].contains("ack=gone"));
}
#[test]
fn duplicate_store_is_locked_out() {
    let dir = TempDir::new().unwrap();
    let first = store(&dir);
    assert!(Store::open(&dir.path().join("worker.sqlite")).is_err());
    drop(first);
    assert!(Store::open(&dir.path().join("worker.sqlite")).is_ok());
}
#[test]
fn payload_purge_retains_digest_and_completed_tombstone() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    accepted(&mut s);
    s.begin_print(ID).unwrap();
    s.finish_print(ID, true).unwrap();
    assert_eq!(s.purge_completed(0).unwrap(), 1);
    assert_eq!(s.ingest(&delivery(2)).unwrap(), Ingest::Stored);
    s.resolve_ack(&ack(2), "sent").unwrap();
    assert!(s.next_print().unwrap().is_none());
    assert!(s.status().unwrap()[0].contains("payload=false"));
    let mut changed = fixture();
    changed["payload"]["notes"] = json!("changed");
    changed["attempt"] = json!(3);
    assert_eq!(
        s.ingest(&contract::parse(changed).unwrap()).unwrap(),
        Ingest::Mismatch
    );
}
#[test]
fn cannot_manually_requeue_completed_job() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    accepted(&mut s);
    s.begin_print(ID).unwrap();
    s.finish_print(ID, true).unwrap();
    assert!(s.reconcile(ID.parse().unwrap(), "retry").is_err());
}
#[test]
fn invalid_payload_uses_bounded_failure_code_with_received_attempt() {
    let dir = TempDir::new().unwrap();
    let mut v = fixture();
    v["attempt"] = json!(7);
    v["payload"]["version"] = json!(9);
    let api = FakeApi {
        polls: VecDeque::from([Ok(Some(v))]),
        ..Default::default()
    };
    let mut worker = Worker::new(store(&dir), api, FakePrinter::default());
    worker.network_tick().unwrap();
    assert_eq!(
        worker.api.calls,
        vec![(ID.into(), 7, Some(FailureCode::UnsupportedVersion))]
    );
    assert!(worker.store.status().unwrap().is_empty());
}
#[test]
fn storage_failure_never_accepts_and_reports_storage_unavailable() {
    let dir = TempDir::new().unwrap();
    let s = store(&dir);
    let other = rusqlite::Connection::open(dir.path().join("worker.sqlite")).unwrap();
    other.execute_batch("CREATE TRIGGER reject_insert BEFORE INSERT ON jobs BEGIN SELECT RAISE(FAIL, 'disk unavailable'); END;").unwrap();
    let api = FakeApi {
        polls: VecDeque::from([Ok(Some(fixture()))]),
        ..Default::default()
    };
    let mut worker = Worker::new(s, api, FakePrinter::default());
    assert!(worker.network_tick().is_err());
    assert_eq!(
        worker.api.calls,
        vec![(ID.into(), 1, Some(FailureCode::StorageUnavailable))]
    );
    assert_eq!(worker.printer.writes, 0);
}
#[test]
fn rate_limit_backoff_applies_to_polling_and_acknowledgements() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    s.ingest(&delivery(1)).unwrap();
    let api = FakeApi {
        reports: VecDeque::from([Err(ApiError::Retry(Some(Duration::from_secs(90))))]),
        ..Default::default()
    };
    let mut worker = Worker::new(s, api, FakePrinter::default());
    assert_eq!(
        worker.network_tick().unwrap(),
        Some(Duration::from_secs(90))
    );
    assert_eq!(worker.api.poll_count, 0);
    assert!(worker.store.next_ack().unwrap().is_some());
}

// Real client against a scripted loopback server; no external service or hardware.
fn server(responses: Vec<String>) -> (String, thread::JoinHandle<Vec<String>>) {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let url = format!("http://{}/", listener.local_addr().unwrap());
    let handle = thread::spawn(move || {
        let mut requests = Vec::new();
        for response in responses {
            let (mut stream, _) = listener.accept().unwrap();
            stream
                .set_read_timeout(Some(Duration::from_secs(5)))
                .unwrap();
            let mut bytes = Vec::new();
            let mut buffer = [0; 1024];
            loop {
                let count = stream.read(&mut buffer).unwrap();
                if count == 0 {
                    break;
                }
                bytes.extend_from_slice(&buffer[..count]);
                if let Some(end) = bytes.windows(4).position(|w| w == b"\r\n\r\n") {
                    let headers = String::from_utf8_lossy(&bytes[..end]).to_lowercase();
                    let length = headers
                        .lines()
                        .find_map(|line| line.strip_prefix("content-length: "))
                        .and_then(|n| n.parse::<usize>().ok())
                        .unwrap_or(0);
                    if bytes.len() >= end + 4 + length {
                        break;
                    }
                }
            }
            requests.push(String::from_utf8(bytes).unwrap());
            if !response.is_empty() {
                stream.write_all(response.as_bytes()).unwrap();
            }
        }
        requests
    });
    (url, handle)
}
fn response(status: u16, headers: &str, body: &str) -> String {
    format!(
        "HTTP/1.1 {status} Test\r\nConnection: close\r\nContent-Length: {}\r\n{headers}\r\n{body}",
        body.len()
    )
}
#[test]
fn real_http_posts_bearer_json_and_echoes_current_attempt() {
    let (url, handle) = server(vec![
        response(200, "", &fixture().to_string()),
        response(
            200,
            "",
            &json!({"print_job_id":ID,"status":"sent"}).to_string(),
        ),
    ]);
    let mut api = HttpApi::new(config::base_url(&url).unwrap(), "test-secret").unwrap();
    assert!(api.poll().unwrap().is_some());
    assert_eq!(api.report(ID, 5, None).unwrap(), ReportResult::Resolved);
    let requests = handle.join().unwrap();
    assert!(requests[0].starts_with("POST /api/printing/next HTTP/1.1"));
    assert!(
        requests[0]
            .to_lowercase()
            .contains("authorization: bearer test-secret")
    );
    assert!(requests[0].ends_with("{}"));
    assert!(requests[1].starts_with(&format!("POST /api/printing/{ID}/accepted ")));
    assert!(requests[1].ends_with("{\"attempt\":5}"));
}
#[test]
fn real_http_handles_204_409_410_404_and_429() {
    let (url, handle) = server(vec![
        response(204, "", ""),
        response(409, "", ""),
        response(410, "", ""),
        response(404, "", ""),
        response(429, "Retry-After: 75\r\n", ""),
    ]);
    let mut api = HttpApi::new(config::base_url(&url).unwrap(), "test-secret").unwrap();
    assert!(api.poll().unwrap().is_none());
    assert_eq!(api.report(ID, 1, None).unwrap(), ReportResult::Conflict);
    assert_eq!(api.report(ID, 1, None).unwrap(), ReportResult::Gone);
    assert_eq!(api.report(ID, 1, None).unwrap(), ReportResult::Gone);
    assert_eq!(
        api.poll().unwrap_err(),
        ApiError::Retry(Some(Duration::from_secs(75)))
    );
    handle.join().unwrap();
}
#[test]
fn real_http_retries_lost_responses_5xx_and_invalid_json() {
    let (url, handle) = server(vec![
        String::new(),
        response(503, "", ""),
        response(200, "", "broken"),
    ]);
    let mut api = HttpApi::new(config::base_url(&url).unwrap(), "test-secret").unwrap();
    for _ in 0..3 {
        assert_eq!(api.poll().unwrap_err(), ApiError::Retry(None));
    }
    handle.join().unwrap();
}
#[test]
fn real_http_fails_closed_for_auth_redirect_and_bad_ack_body() {
    let (url, handle) = server(vec![
        response(401, "", ""),
        response(302, "Location: https://example.invalid/\r\n", ""),
        response(200, "", "{}"),
        response(422, "", ""),
    ]);
    let mut api = HttpApi::new(config::base_url(&url).unwrap(), "test-secret").unwrap();
    assert!(matches!(api.poll(), Err(ApiError::Fatal(_))));
    assert!(matches!(api.poll(), Err(ApiError::Fatal(_))));
    assert!(matches!(api.report(ID, 1, None), Err(ApiError::Fatal(_))));
    assert!(matches!(api.report(ID, 1, None), Err(ApiError::Fatal(_))));
    handle.join().unwrap();
}
#[test]
fn url_requires_https_except_loopback_and_preserves_subdirectory() {
    assert!(config::base_url("http://shop.example").is_err());
    assert!(config::base_url("https://user:pass@shop.example").is_err());
    assert!(config::base_url("https://shop.example?token=x").is_err());
    assert!(config::base_url("http://127.0.0.1:8000").is_ok());
    assert_eq!(
        config::base_url("https://shop.example/shop")
            .unwrap()
            .join("api/printing/next")
            .unwrap()
            .as_str(),
        "https://shop.example/shop/api/printing/next"
    );
}

fn backend_config(backend: config::BackendConfig) -> config::Config {
    config::Config {
        url: config::base_url("http://127.0.0.1:8000").unwrap(),
        token: "unused-test-token".into(),
        interval: Duration::from_secs(3),
        backend,
        font: std::env::var("PRINT_TEST_FONT")
            .unwrap_or_else(|_| "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf".into())
            .into(),
        width: 384,
        font_px: 28.0,
        cut: false,
        retention_days: 30,
    }
}
#[test]
fn real_tcp_backend_transmits_prepared_raster_bytes() {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let address = listener.local_addr().unwrap();
    let handle = thread::spawn(move || {
        let (mut stream, _) = listener.accept().unwrap();
        stream
            .set_read_timeout(Some(Duration::from_secs(5)))
            .unwrap();
        let mut bytes = Vec::new();
        stream.read_to_end(&mut bytes).unwrap();
        bytes
    });
    let mut backend =
        crate::printer::Backend::from_config(&backend_config(config::BackendConfig::Tcp(address)))
            .unwrap();
    let bytes = backend.prepare(&delivery(1).payload).unwrap();
    backend.write(&bytes).unwrap();
    assert_eq!(handle.join().unwrap(), bytes);
}
#[test]
fn real_tcp_backend_returns_connection_error() {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let address = listener.local_addr().unwrap();
    drop(listener);
    let mut backend =
        crate::printer::Backend::from_config(&backend_config(config::BackendConfig::Tcp(address)))
            .unwrap();
    assert!(backend.write(b"test").is_err());
}
#[cfg(target_os = "linux")]
#[test]
fn usb_backend_never_creates_or_overwrites_a_regular_file() {
    let dir = TempDir::new().unwrap();
    let path = dir.path().join("not-a-printer");
    let mut backend = crate::printer::Backend::from_config(&backend_config(
        config::BackendConfig::Usb(path.clone()),
    ))
    .unwrap();
    assert!(backend.write(b"test").is_err());
    assert!(!path.exists());
    std::fs::write(&path, b"keep").unwrap();
    assert_eq!(
        backend.write(b"test").unwrap_err().kind(),
        io::ErrorKind::InvalidInput
    );
    assert_eq!(std::fs::read(path).unwrap(), b"keep");
}
#[test]
fn unresolved_printer_error_pauses_later_receipts_but_allows_acceptance() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    accepted(&mut s);
    s.begin_print(ID).unwrap();
    s.finish_print(ID, false).unwrap();
    let mut second = fixture();
    second["payload"]["print_job_id"] = json!("00000000-0000-4000-8000-000000000002");
    let second_id = second["payload"]["print_job_id"]
        .as_str()
        .unwrap()
        .to_owned();
    let api = FakeApi {
        polls: VecDeque::from([Ok(Some(second))]),
        ..Default::default()
    };
    let mut worker = Worker::new(s, api, FakePrinter::default());
    worker.network_tick().unwrap();
    worker.network_tick().unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.api.calls, vec![(second_id, 1, None)]);
    assert_eq!(worker.printer.writes, 0);
    worker
        .store
        .reconcile(ID.parse().unwrap(), "completed")
        .unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.printer.writes, 1);
}
#[test]
fn restart_between_confirmed_ack_and_output_resumes_queued_job() {
    let dir = TempDir::new().unwrap();
    {
        let mut s = store(&dir);
        accepted(&mut s);
    }
    let mut worker = Worker::new(store(&dir), FakeApi::default(), FakePrinter::default());
    worker.print_tick().unwrap();
    worker.print_tick().unwrap();
    assert_eq!(worker.printer.writes, 1);
    assert!(worker.api.calls.is_empty());
}
#[test]
fn failed_completion_commit_after_output_recovers_as_uncertain() {
    let dir = TempDir::new().unwrap();
    let mut s = store(&dir);
    accepted(&mut s);
    let other = rusqlite::Connection::open(dir.path().join("worker.sqlite")).unwrap();
    other.execute_batch("CREATE TRIGGER reject_complete BEFORE UPDATE ON jobs WHEN NEW.state='completed' BEGIN SELECT RAISE(FAIL,'disk full'); END;").unwrap();
    let mut worker = Worker::new(s, FakeApi::default(), FakePrinter::default());
    assert!(worker.print_tick().is_err());
    assert_eq!(worker.printer.writes, 1);
    drop(worker);
    other
        .execute_batch("DROP TRIGGER reject_complete;")
        .unwrap();
    let mut worker = Worker::new(store(&dir), FakeApi::default(), FakePrinter::default());
    worker.print_tick().unwrap();
    assert_eq!(worker.printer.writes, 0);
    assert!(worker.store.status().unwrap()[0].contains("output=uncertain"));
}
#[test]
fn rejection_conflict_does_not_flip_outcome_or_create_print_job() {
    let dir = TempDir::new().unwrap();
    let mut v = fixture();
    v["payload"]["type"] = json!("delivery");
    let api = FakeApi {
        polls: VecDeque::from([Ok(Some(v))]),
        reports: VecDeque::from([Ok(ReportResult::Conflict)]),
        ..Default::default()
    };
    let mut worker = Worker::new(store(&dir), api, FakePrinter::default());
    worker.network_tick().unwrap();
    worker.network_tick().unwrap();
    assert_eq!(
        worker.api.calls,
        vec![(ID.into(), 1, Some(FailureCode::InvalidPayload))]
    );
    assert!(worker.store.status().unwrap().is_empty());
}
#[test]
fn retry_after_http_date_is_respected() {
    let date =
        (chrono::Utc::now() + chrono::Duration::seconds(120)).format("%a, %d %b %Y %H:%M:%S GMT");
    let (url, handle) = server(vec![response(429, &format!("Retry-After: {date}\r\n"), "")]);
    let mut api = HttpApi::new(config::base_url(&url).unwrap(), "test-secret").unwrap();
    match api.poll().unwrap_err() {
        ApiError::Retry(Some(delay)) => assert!((110..=120).contains(&delay.as_secs())),
        other => panic!("expected retry deadline, got {other:?}"),
    }
    handle.join().unwrap();
}
