use std::{
    fs::{File, OpenOptions},
    path::Path,
    time::Duration,
};

use anyhow::{Context, Result, bail};
use rusqlite::{Connection, OptionalExtension, params};
use uuid::Uuid;

use crate::contract::Delivery;

pub struct Store {
    db: Connection,
    _lock: File,
}

#[derive(Debug, PartialEq, Eq)]
pub enum Ingest {
    Stored,
    Stale,
    Mismatch,
}

pub struct Ack {
    pub id: String,
    pub attempt: i64,
}

pub struct Printable {
    pub id: String,
    pub json: String,
}

impl Store {
    pub fn open(path: &Path) -> Result<Self> {
        if let Some(parent) = path.parent().filter(|p| !p.as_os_str().is_empty()) {
            std::fs::create_dir_all(parent).context("create worker state directory")?;
        }
        let lock_path = path.with_extension("lockfile");
        let lock = OpenOptions::new()
            .create(true)
            .truncate(false)
            .read(true)
            .write(true)
            .open(lock_path)
            .context("open worker lock")?;
        lock.try_lock()
            .context("worker state is already locked by another process")?;
        let db = Connection::open(path).context("open worker database")?;
        db.busy_timeout(Duration::from_secs(5))?;
        // DELETE journal + FULL includes a durable commit before any callback
        // or device write. No WAL sidecars need a separate checkpoint/backup.
        db.execute_batch(
            "PRAGMA journal_mode=DELETE;
             PRAGMA synchronous=FULL;
             PRAGMA secure_delete=ON;
             CREATE TABLE IF NOT EXISTS jobs (
               id TEXT PRIMARY KEY,
               digest TEXT NOT NULL,
               payload TEXT,
               attempt INTEGER NOT NULL CHECK(attempt > 0),
               ack TEXT NOT NULL CHECK(ack IN ('pending','sent','blocked','gone')),
               state TEXT NOT NULL CHECK(state IN ('queued','printing','completed','uncertain','held')),
               received_at INTEGER NOT NULL,
               completed_at INTEGER,
               ack_tried_at INTEGER NOT NULL DEFAULT 0
             );
             CREATE INDEX IF NOT EXISTS jobs_ack ON jobs(ack, ack_tried_at);
             CREATE INDEX IF NOT EXISTS jobs_print ON jobs(state, ack, received_at);"
        )?;
        // Output might have reached the printer. Never replay it automatically.
        db.execute(
            "UPDATE jobs SET state='uncertain' WHERE state='printing'",
            [],
        )?;
        Ok(Self { db, _lock: lock })
    }

    pub fn ingest(&mut self, delivery: &Delivery) -> Result<Ingest> {
        let tx = self.db.transaction()?;
        let id = delivery.payload.print_job_id.to_string();
        let existing: Option<(String, i64)> = tx
            .query_row(
                "SELECT digest, attempt FROM jobs WHERE id=?1",
                [&id],
                |row| Ok((row.get(0)?, row.get(1)?)),
            )
            .optional()?;
        match existing {
            Some((_, attempt)) if delivery.attempt < attempt => return Ok(Ingest::Stale),
            Some((digest, _)) if digest != delivery.digest => return Ok(Ingest::Mismatch),
            Some((_, attempt)) => {
                // Same attempt after 409 must remain blocked. A new claim can
                // acknowledge an existing completed/uncertain job, never requeue it.
                if delivery.attempt > attempt {
                    tx.execute(
                        "UPDATE jobs SET attempt=?2, ack='pending', ack_tried_at=0 WHERE id=?1",
                        params![id, delivery.attempt],
                    )?;
                }
            }
            None => {
                tx.execute(
                    "INSERT INTO jobs(id,digest,payload,attempt,ack,state,received_at)
                    VALUES (?1,?2,?3,?4,'pending','queued',unixepoch())",
                    params![id, delivery.digest, delivery.json, delivery.attempt],
                )?;
            }
        }
        tx.commit()?;
        Ok(Ingest::Stored)
    }

    pub fn next_ack(&self) -> Result<Option<Ack>> {
        Ok(self.db.query_row(
            "SELECT id,attempt FROM jobs WHERE ack='pending' ORDER BY ack_tried_at,received_at,id LIMIT 1",
            [], |r| Ok(Ack { id: r.get(0)?, attempt: r.get(1)? }),
        ).optional()?)
    }

    pub fn tried_ack(&self, ack: &Ack) -> Result<()> {
        self.db.execute(
            "UPDATE jobs SET ack_tried_at=unixepoch() WHERE id=?1 AND attempt=?2",
            params![ack.id, ack.attempt],
        )?;
        Ok(())
    }

    pub fn resolve_ack(&self, ack: &Ack, status: &str) -> Result<()> {
        // Conditional update is an extra guard against delayed local results.
        self.db.execute(
            "UPDATE jobs SET ack=?3 WHERE id=?1 AND attempt=?2 AND ack='pending'",
            params![ack.id, ack.attempt, status],
        )?;
        Ok(())
    }

    pub fn next_print(&self) -> Result<Option<Printable>> {
        Ok(self.db.query_row(
            "SELECT id,payload FROM jobs WHERE state='queued' AND ack='sent' AND payload IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM jobs WHERE state IN ('uncertain','held')) ORDER BY received_at,id LIMIT 1", [],
            |r| Ok(Printable { id: r.get(0)?, json: r.get(1)? }),
        ).optional()?)
    }

    pub fn begin_print(&self, id: &str) -> Result<()> {
        let count = self.db.execute(
            "UPDATE jobs SET state='printing' WHERE id=?1 AND state='queued' AND ack='sent'",
            [id],
        )?;
        if count != 1 {
            bail!("job is not eligible for output");
        }
        Ok(())
    }

    pub fn finish_print(&self, id: &str, success: bool) -> Result<()> {
        self.db.execute(
            "UPDATE jobs SET state=?2, completed_at=CASE WHEN ?3 THEN unixepoch() ELSE NULL END
            WHERE id=?1 AND state='printing'",
            params![id, if success { "completed" } else { "uncertain" }, success],
        )?;
        Ok(())
    }

    pub fn hold(&self, id: &str) -> Result<()> {
        self.db.execute(
            "UPDATE jobs SET state='held' WHERE id=?1 AND state='queued'",
            [id],
        )?;
        Ok(())
    }

    pub fn purge_completed(&self, days: u32) -> Result<usize> {
        Ok(self.db.execute(
            "UPDATE jobs SET payload=NULL WHERE state='completed' AND payload IS NOT NULL
            AND completed_at <= unixepoch()-?1",
            [i64::from(days) * 86400],
        )?)
    }

    pub fn status(&self) -> Result<Vec<String>> {
        let mut stmt = self.db.prepare(
            "SELECT id,attempt,ack,state,payload IS NOT NULL FROM jobs ORDER BY received_at,id",
        )?;
        Ok(stmt
            .query_map([], |r| {
                Ok(format!(
                    "{} attempt={} ack={} output={} payload={}",
                    r.get::<_, String>(0)?,
                    r.get::<_, i64>(1)?,
                    r.get::<_, String>(2)?,
                    r.get::<_, String>(3)?,
                    r.get::<_, bool>(4)?
                ))
            })?
            .collect::<rusqlite::Result<Vec<_>>>()?)
    }

    pub fn reconcile(&self, id: Uuid, action: &str) -> Result<()> {
        let count = match action {
            "completed" => self.db.execute(
                "UPDATE jobs SET state='completed',completed_at=unixepoch()
                WHERE id=?1 AND state IN ('held','uncertain')",
                [id.to_string()],
            )?,
            "retry" => self.db.execute(
                "UPDATE jobs SET state='queued' WHERE id=?1
                AND state IN ('held','uncertain') AND payload IS NOT NULL AND ack='sent'",
                [id.to_string()],
            )?,
            _ => bail!("resolution must be completed or retry"),
        };
        if count != 1 {
            bail!("job is not eligible for this operator resolution");
        }
        Ok(())
    }
}
