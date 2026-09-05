mod api;
mod config;
mod contract;
mod printer;
mod receipt;
mod store;
mod worker;

#[cfg(test)]
mod tests;

use anyhow::{Result, bail};
use std::{
    env, thread,
    time::{Duration, Instant},
};

fn main() {
    // Restrict new SQLite/journal/lock files to the service account on Unix.
    #[cfg(unix)]
    unsafe {
        libc::umask(0o077);
    }
    if let Err(error) = run() {
        eprintln!("event=fatal error={error}");
        std::process::exit(1);
    }
}

fn run() -> Result<()> {
    let args: Vec<String> = env::args().skip(1).collect();
    if matches!(args.first().map(String::as_str), Some("--help" | "-h")) {
        println!(
            "kitchen-print-worker [run|status|resolve UUID completed|resolve UUID retry]\nConfiguration: see printing-worker/README.md. Stop the service before status/resolve."
        );
        return Ok(());
    }
    match args
        .iter()
        .map(String::as_str)
        .collect::<Vec<_>>()
        .as_slice()
    {
        ["status"] => {
            for line in store::Store::open(&config::database_path())?.status()? {
                println!("{line}");
            }
            return Ok(());
        }
        ["resolve", id, action] => {
            let id = uuid::Uuid::parse_str(id)?;
            store::Store::open(&config::database_path())?.reconcile(id, action)?;
            eprintln!("event=operator_resolution job={id} action={action}");
            return Ok(());
        }
        [] | ["run"] => (),
        _ => bail!("invalid arguments; use --help"),
    }
    let config = config::Config::from_env()?;
    let backend = printer::Backend::from_config(&config)?;
    let api = api::HttpApi::new(config.url, &config.token)?;
    let store = store::Store::open(&config::database_path())?;
    let mut worker = worker::Worker::new(store, api, backend);
    let mut next_http = Instant::now();
    let mut next_purge = Instant::now();
    eprintln!("event=started poll_seconds={}", config.interval.as_secs());
    loop {
        if Instant::now() >= next_purge {
            worker.store.purge_completed(config.retention_days)?;
            next_purge = Instant::now() + Duration::from_secs(3600);
        }
        if Instant::now() >= next_http {
            let delay = worker.network_tick()?.unwrap_or(config.interval);
            next_http = Instant::now()
                .checked_add(delay)
                .ok_or_else(|| anyhow::anyhow!("Retry-After exceeds supported duration"))?;
        }
        worker.print_tick()?;
        thread::sleep(Duration::from_millis(100));
    }
}
