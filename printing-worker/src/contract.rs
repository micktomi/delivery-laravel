use chrono::{DateTime, FixedOffset};
use chrono_tz::Tz;
use serde::Deserialize;
use serde_json::Value;
use sha2::{Digest, Sha256};
use uuid::Uuid;

pub const MAX_RESPONSE_BYTES: u64 = 1_048_576;

#[derive(Clone, Debug, Deserialize)]
pub struct Payload {
    pub version: u64,
    #[serde(rename = "type")]
    pub kind: String,
    pub print_job_id: Uuid,
    pub order_id: u64,
    pub display_number: u64,
    pub placed_at: DateTime<FixedOffset>,
    pub accepted_at: DateTime<FixedOffset>,
    pub timezone: Tz,
    pub customer: Customer,
    pub notes: Option<String>,
    pub items: Vec<Item>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct Customer {
    pub name: String,
}

#[derive(Clone, Debug, Deserialize)]
pub struct Item {
    pub name: String,
    pub quantity: u64,
    pub options: Vec<ItemOption>,
    pub notes: Option<String>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct ItemOption {
    pub group: String,
    pub value: String,
}

pub struct Delivery {
    pub attempt: i64,
    pub payload: Payload,
    pub json: String,
    pub digest: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FailureCode {
    StorageUnavailable,
    InvalidPayload,
    UnsupportedVersion,
}

impl FailureCode {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::StorageUnavailable => "storage_unavailable",
            Self::InvalidPayload => "invalid_payload",
            Self::UnsupportedVersion => "unsupported_version",
        }
    }
}

#[derive(Debug)]
pub struct InvalidDelivery {
    // If either is unreadable, we cannot safely address a callback.
    pub identity: Option<(Uuid, i64)>,
    pub code: FailureCode,
}

pub fn parse(value: Value) -> Result<Delivery, InvalidDelivery> {
    let identity = value["payload"]["print_job_id"]
        .as_str()
        .and_then(|id| Uuid::parse_str(id).ok())
        .zip(value["attempt"].as_i64().filter(|n| *n > 0));
    let invalid = |code| InvalidDelivery { identity, code };
    let (_, attempt) = identity.ok_or_else(|| invalid(FailureCode::InvalidPayload))?;
    let raw = &value["payload"];
    let version = raw["version"]
        .as_u64()
        .ok_or_else(|| invalid(FailureCode::InvalidPayload))?;
    if version != 1 {
        return Err(invalid(FailureCode::UnsupportedVersion));
    }
    let payload: Payload =
        serde_json::from_value(raw.clone()).map_err(|_| invalid(FailureCode::InvalidPayload))?;
    if payload.version != 1 || payload.kind != "kitchen" {
        return Err(invalid(FailureCode::InvalidPayload));
    }
    // serde_json's default maps are sorted; object order and whitespace do not
    // affect the digest. Array order, unknown fields and JSON types do.
    let json = serde_json::to_string(raw).map_err(|_| invalid(FailureCode::InvalidPayload))?;
    let digest = format!("{:x}", Sha256::digest(json.as_bytes()));
    Ok(Delivery {
        attempt,
        payload,
        json,
        digest,
    })
}
