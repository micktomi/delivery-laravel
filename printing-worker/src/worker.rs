use std::time::Duration;

use anyhow::{Result, bail};

use crate::{
    api::{ApiError, ReportResult, Transport},
    contract::{self, FailureCode, Payload},
    printer::Printer,
    store::{Ingest, Store},
};

pub struct Worker<T: Transport, P: Printer> {
    pub store: Store,
    pub api: T,
    pub printer: P,
    failures: u32,
}

impl<T: Transport, P: Printer> Worker<T, P> {
    pub fn new(store: Store, api: T, printer: P) -> Self {
        Self {
            store,
            api,
            printer,
            failures: 0,
        }
    }

    // At most one persisted acceptance + one poll + one rejection per tick.
    // All requests share one backoff, including callbacks, on a 429.
    pub fn network_tick(&mut self) -> Result<Option<Duration>> {
        match self.exchange() {
            Ok(()) => {
                self.failures = 0;
                Ok(None)
            }
            Err(ApiError::Retry(delay)) => {
                self.failures = self.failures.saturating_add(1);
                let exponential = Duration::from_secs((3_u64 << self.failures.min(5)).min(60));
                let delay = delay.unwrap_or(exponential).max(exponential);
                eprintln!("event=http_backoff seconds={}", delay.as_secs());
                Ok(Some(delay))
            }
            Err(ApiError::Fatal(message)) => bail!("{message}"),
        }
    }

    fn exchange(&mut self) -> Result<(), ApiError> {
        let storage = |_| ApiError::Fatal("local storage operation failed; worker stopped");
        if let Some(ack) = self.store.next_ack().map_err(storage)? {
            self.store.tried_ack(&ack).map_err(storage)?;
            let outcome = self.api.report(&ack.id, ack.attempt, None)?;
            let status = match outcome {
                ReportResult::Resolved => "sent",
                ReportResult::Conflict => "blocked",
                ReportResult::Gone => "gone",
            };
            self.store.resolve_ack(&ack, status).map_err(storage)?;
            eprintln!(
                "event=ack job={} attempt={} status={status}",
                ack.id, ack.attempt
            );
        }
        if let Some(value) = self.api.poll()? {
            match contract::parse(value) {
                Ok(delivery) => {
                    let id = delivery.payload.print_job_id.to_string();
                    match self.store.ingest(&delivery) {
                        Ok(Ingest::Stored) => {
                            eprintln!("event=stored job={id} attempt={}", delivery.attempt)
                        }
                        Ok(Ingest::Stale) => {
                            eprintln!("event=stale_poll job={id} attempt={}", delivery.attempt)
                        }
                        Ok(Ingest::Mismatch) => {
                            // Keep the original immutable snapshot and lifecycle.
                            self.reject(&id, delivery.attempt, FailureCode::InvalidPayload)?;
                        }
                        Err(_) => {
                            // Never accepted. A lost failure response is safe:
                            // Laravel either failed it or will reoffer after lease.
                            let _ = self.api.report(
                                &id,
                                delivery.attempt,
                                Some(FailureCode::StorageUnavailable),
                            );
                            return Err(ApiError::Fatal(
                                "cannot durably store delivery; worker stopped",
                            ));
                        }
                    }
                }
                Err(invalid) => {
                    if let Some((id, attempt)) = invalid.identity {
                        self.reject(&id.to_string(), attempt, invalid.code)?;
                    } else {
                        return Err(ApiError::Fatal(
                            "malformed poll identity; cannot safely acknowledge delivery",
                        ));
                    }
                }
            }
        }
        Ok(())
    }

    fn reject(&mut self, id: &str, attempt: i64, code: FailureCode) -> Result<(), ApiError> {
        let result = self.api.report(id, attempt, Some(code))?;
        eprintln!(
            "event=rejected job={id} attempt={attempt} code={} result={result:?}",
            code.as_str()
        );
        // In particular, never invert an outcome on 409.
        Ok(())
    }

    pub fn print_tick(&mut self) -> Result<()> {
        let Some(job) = self.store.next_print()? else {
            return Ok(());
        };
        let payload: Payload = serde_json::from_str(&job.json)
            .map_err(|_| anyhow::anyhow!("stored payload is unreadable"))?;
        let bytes = match self.printer.prepare(&payload) {
            Ok(bytes) => bytes,
            Err(_) => {
                self.store.hold(&job.id)?;
                eprintln!("event=render_held job={} action=operator_required", job.id);
                return Ok(());
            }
        };
        self.store.begin_print(&job.id)?;
        let success = self.printer.write(&bytes).is_ok();
        self.store.finish_print(&job.id, success)?;
        eprintln!(
            "event=output job={} state={}",
            job.id,
            if success { "completed" } else { "uncertain" }
        );
        // Physical errors are exclusively local. No /failed callback here.
        Ok(())
    }
}
