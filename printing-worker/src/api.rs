use std::{io::Read, time::Duration};

use reqwest::{
    Url,
    blocking::{Client, Response},
    header::{AUTHORIZATION, HeaderMap, HeaderValue, RETRY_AFTER},
};
use serde_json::{Value, json};

use crate::contract::{FailureCode, MAX_RESPONSE_BYTES};

#[derive(Debug, PartialEq, Eq)]
pub enum ApiError {
    Retry(Option<Duration>),
    Fatal(&'static str),
}

#[derive(Debug, PartialEq, Eq)]
pub enum ReportResult {
    Resolved,
    Conflict,
    Gone,
}

pub trait Transport {
    fn poll(&mut self) -> Result<Option<Value>, ApiError>;
    fn report(
        &mut self,
        id: &str,
        attempt: i64,
        failure: Option<FailureCode>,
    ) -> Result<ReportResult, ApiError>;
}

pub struct HttpApi {
    client: Client,
    base: Url,
}

impl HttpApi {
    pub fn new(base: Url, token: &str) -> anyhow::Result<Self> {
        let mut headers = HeaderMap::new();
        let mut authorization = HeaderValue::from_str(&format!("Bearer {token}"))
            .map_err(|_| anyhow::anyhow!("invalid bearer token header"))?;
        authorization.set_sensitive(true);
        headers.insert(AUTHORIZATION, authorization);
        headers.insert("Accept", HeaderValue::from_static("application/json"));
        let client = Client::builder()
            .default_headers(headers)
            .redirect(reqwest::redirect::Policy::none())
            .connect_timeout(Duration::from_secs(5))
            .timeout(Duration::from_secs(10))
            .build()
            .map_err(|_| anyhow::anyhow!("cannot initialize HTTPS client"))?;
        Ok(Self { client, base })
    }

    fn post(&self, path: &str, body: Value) -> Result<Response, ApiError> {
        let url = self
            .base
            .join(&format!("api/printing/{path}"))
            .map_err(|_| ApiError::Fatal("invalid endpoint configuration"))?;
        self.client
            .post(url)
            .json(&body)
            .send()
            .map_err(|_| ApiError::Retry(None))
    }
}

fn retry_after(response: &Response) -> Option<Duration> {
    let raw = response.headers().get(RETRY_AFTER)?.to_str().ok()?;
    if let Ok(seconds) = raw.parse::<u64>() {
        return Some(Duration::from_secs(seconds.max(1)));
    }
    let date = chrono::DateTime::parse_from_rfc2822(raw).ok()?;
    Some(Duration::from_secs(
        (date.timestamp() - chrono::Utc::now().timestamp()).max(1) as u64,
    ))
}

fn error(response: &Response) -> ApiError {
    match response.status().as_u16() {
        401 | 403 => ApiError::Fatal("Laravel authentication rejected; check PRINT_WORKER_TOKEN"),
        429 => ApiError::Retry(Some(
            retry_after(response).unwrap_or(Duration::from_secs(60)),
        )),
        500..=599 => ApiError::Retry(retry_after(response)),
        _ => ApiError::Fatal("unexpected HTTP status; check URL and deployed pull contract"),
    }
}

fn json_body(response: Response) -> Result<Value, ApiError> {
    let mut data = Vec::new();
    response
        .take(MAX_RESPONSE_BYTES + 1)
        .read_to_end(&mut data)
        .map_err(|_| ApiError::Retry(None))?;
    if data.len() as u64 > MAX_RESPONSE_BYTES {
        return Err(ApiError::Fatal("response exceeds 1 MiB"));
    }
    serde_json::from_slice(&data).map_err(|_| ApiError::Retry(None))
}

impl Transport for HttpApi {
    fn poll(&mut self) -> Result<Option<Value>, ApiError> {
        let response = self.post("next", json!({}))?;
        match response.status().as_u16() {
            204 => Ok(None),
            200 => Ok(Some(json_body(response)?)),
            _ => Err(error(&response)),
        }
    }

    fn report(
        &mut self,
        id: &str,
        attempt: i64,
        failure: Option<FailureCode>,
    ) -> Result<ReportResult, ApiError> {
        let (path, body, expected) = match failure {
            None => ("accepted", json!({"attempt": attempt}), "sent"),
            Some(code) => (
                "failed",
                json!({"attempt": attempt, "error": code.as_str()}),
                "failed",
            ),
        };
        let response = self.post(&format!("{id}/{path}"), body)?;
        match response.status().as_u16() {
            200 => {
                let body = json_body(response)?;
                if body["print_job_id"].as_str() != Some(id)
                    || body["status"].as_str() != Some(expected)
                {
                    return Err(ApiError::Fatal("invalid acknowledgement response"));
                }
                Ok(ReportResult::Resolved)
            }
            409 => Ok(ReportResult::Conflict),
            404 | 410 => Ok(ReportResult::Gone),
            422 => Err(ApiError::Fatal("callback validation rejected by Laravel")),
            _ => Err(error(&response)),
        }
    }
}
