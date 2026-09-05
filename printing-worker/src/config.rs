use std::{env, net::SocketAddr, path::PathBuf, time::Duration};

use anyhow::{Context, Result, bail};
use reqwest::Url;

pub enum BackendConfig {
    Stdout,
    Tcp(SocketAddr),
    Usb(PathBuf),
}

pub struct Config {
    pub url: Url,
    pub token: String,
    pub interval: Duration,
    pub backend: BackendConfig,
    pub font: PathBuf,
    pub width: usize,
    pub font_px: f32,
    pub cut: bool,
    pub retention_days: u32,
}

pub fn database_path() -> PathBuf {
    env::var_os("PRINT_WORKER_DB")
        .map(PathBuf::from)
        .unwrap_or_else(|| PathBuf::from("data/worker.sqlite"))
}

fn number(name: &str, default: u64, min: u64, max: u64) -> Result<u64> {
    let n = env::var(name)
        .unwrap_or_else(|_| default.to_string())
        .parse::<u64>()
        .with_context(|| format!("{name} must be an integer"))?;
    if !(min..=max).contains(&n) {
        bail!("{name} is outside {min}..={max}");
    }
    Ok(n)
}

pub fn base_url(raw: &str) -> Result<Url> {
    let mut url = Url::parse(raw).map_err(|_| anyhow::anyhow!("invalid LARAVEL_URL"))?;
    if !url.username().is_empty()
        || url.password().is_some()
        || url.query().is_some()
        || url.fragment().is_some()
    {
        bail!("LARAVEL_URL must not contain credentials, query or fragment");
    }
    let local = matches!(url.host_str(), Some("localhost" | "127.0.0.1" | "[::1]"));
    if url.scheme() != "https" && !(url.scheme() == "http" && local) {
        bail!("LARAVEL_URL requires HTTPS; HTTP is allowed only on loopback for development");
    }
    url.set_path(&format!("{}/", url.path().trim_end_matches('/')));
    Ok(url)
}

impl Config {
    pub fn from_env() -> Result<Self> {
        let url = base_url(&env::var("LARAVEL_URL").context("LARAVEL_URL is required")?)?;
        let token = env::var("PRINT_WORKER_TOKEN").context("PRINT_WORKER_TOKEN is required")?;
        if token.trim().is_empty() || token.chars().any(char::is_control) {
            bail!("PRINT_WORKER_TOKEN must be nonempty and contain no control characters");
        }
        let backend = match env::var("PRINTER_BACKEND")
            .unwrap_or_else(|_| "stdout".into())
            .as_str()
        {
            "stdout" => BackendConfig::Stdout,
            "escpos-tcp" => BackendConfig::Tcp(
                env::var("PRINTER_ADDRESS")
                    .context("PRINTER_ADDRESS is required (IP:port)")?
                    .parse()
                    .context("PRINTER_ADDRESS must be an IP:port socket address")?,
            ),
            "escpos-usb" => BackendConfig::Usb(PathBuf::from(
                env::var("PRINTER_DEVICE").context("PRINTER_DEVICE is required")?,
            )),
            _ => bail!("PRINTER_BACKEND must be stdout, escpos-tcp or escpos-usb"),
        };
        let width = number("PRINTER_WIDTH_DOTS", 576, 128, 832)? as usize;
        if !width.is_multiple_of(8) {
            bail!("PRINTER_WIDTH_DOTS must be a multiple of 8");
        }
        Ok(Self {
            url,
            token,
            backend,
            width,
            interval: Duration::from_secs(number("PRINT_POLL_SECONDS", 3, 2, 15)?),
            font: env::var_os("PRINTER_FONT")
                .map(PathBuf::from)
                .unwrap_or_else(|| {
                    PathBuf::from("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")
                }),
            font_px: number("PRINTER_FONT_PX", 28, 16, 48)? as f32,
            cut: match env::var("PRINTER_CUT")
                .unwrap_or_else(|_| "false".into())
                .as_str()
            {
                "true" => true,
                "false" => false,
                _ => bail!("PRINTER_CUT must be true or false"),
            },
            retention_days: number("PRINT_PAYLOAD_RETENTION_DAYS", 30, 1, 3650)? as u32,
        })
    }
}
