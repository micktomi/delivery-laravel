use std::{
    io::{self, Write},
    net::{SocketAddr, TcpStream},
    path::PathBuf,
    time::{Duration, Instant},
};

use anyhow::Result;

use crate::{
    config::{BackendConfig, Config},
    contract::Payload,
    receipt::{self, Rasterizer},
};

const OUTPUT_TIMEOUT: Duration = Duration::from_secs(10);

pub trait Printer {
    // Rendering must finish before durable state moves to `printing`.
    fn prepare(&self, payload: &Payload) -> Result<Vec<u8>>;
    fn write(&mut self, bytes: &[u8]) -> io::Result<()>;
}

enum Destination {
    Stdout,
    Tcp(SocketAddr),
    Usb(PathBuf),
}

pub struct Backend {
    destination: Destination,
    rasterizer: Option<Rasterizer>,
}

impl Backend {
    pub fn from_config(config: &Config) -> Result<Self> {
        let destination = match &config.backend {
            BackendConfig::Stdout => Destination::Stdout,
            BackendConfig::Tcp(address) => Destination::Tcp(*address),
            BackendConfig::Usb(path) => Destination::Usb(path.clone()),
        };
        let rasterizer = match destination {
            Destination::Stdout => None,
            _ => Some(Rasterizer::new(
                &config.font,
                config.width,
                config.font_px,
                config.cut,
            )?),
        };
        Ok(Self {
            destination,
            rasterizer,
        })
    }
}

impl Printer for Backend {
    fn prepare(&self, payload: &Payload) -> Result<Vec<u8>> {
        let text = receipt::text(payload);
        match &self.rasterizer {
            Some(rasterizer) => rasterizer.render(&text),
            None => Ok(text.into_bytes()),
        }
    }

    fn write(&mut self, bytes: &[u8]) -> io::Result<()> {
        match &self.destination {
            Destination::Stdout => {
                let mut stdout = io::stdout().lock();
                stdout.write_all(bytes)?;
                stdout.flush()
            }
            Destination::Tcp(address) => {
                let started = Instant::now();
                let mut stream = TcpStream::connect_timeout(address, OUTPUT_TIMEOUT)?;
                let mut remaining = bytes;
                while !remaining.is_empty() {
                    let timeout = OUTPUT_TIMEOUT
                        .checked_sub(started.elapsed())
                        .filter(|d| !d.is_zero())
                        .ok_or_else(|| io::Error::from(io::ErrorKind::TimedOut))?;
                    stream.set_write_timeout(Some(timeout))?;
                    match stream.write(remaining) {
                        Ok(0) => return Err(io::ErrorKind::WriteZero.into()),
                        Ok(count) => remaining = &remaining[count..],
                        Err(e) if e.kind() == io::ErrorKind::Interrupted => continue,
                        Err(e) => return Err(e),
                    }
                }
                stream.flush()
            }
            Destination::Usb(path) => usb_write(path, bytes),
        }
    }
}

#[cfg(target_os = "linux")]
fn usb_write(path: &std::path::Path, bytes: &[u8]) -> io::Result<()> {
    use std::{
        fs::OpenOptions,
        os::{
            fd::AsRawFd,
            unix::fs::{FileTypeExt, OpenOptionsExt},
        },
    };
    let mut device = OpenOptions::new()
        .write(true)
        .custom_flags(libc::O_NONBLOCK | libc::O_NOFOLLOW | libc::O_CLOEXEC)
        .open(path)?;
    if !device.metadata()?.file_type().is_char_device() {
        return Err(io::Error::new(
            io::ErrorKind::InvalidInput,
            "PRINTER_DEVICE must be a character device",
        ));
    }
    let started = Instant::now();
    let mut remaining = bytes;
    while !remaining.is_empty() {
        let timeout = OUTPUT_TIMEOUT
            .checked_sub(started.elapsed())
            .ok_or_else(|| io::Error::from(io::ErrorKind::TimedOut))?;
        let mut fd = libc::pollfd {
            fd: device.as_raw_fd(),
            events: libc::POLLOUT,
            revents: 0,
        };
        // SAFETY: fd points to one initialized pollfd and the open File outlives
        // the call. The finite timeout bounds a disconnected/stalled USB device.
        let result = unsafe { libc::poll(&mut fd, 1, timeout.as_millis().max(1) as i32) };
        if result == 0 {
            return Err(io::ErrorKind::TimedOut.into());
        }
        if result < 0 {
            let error = io::Error::last_os_error();
            if error.kind() == io::ErrorKind::Interrupted {
                continue;
            }
            return Err(error);
        }
        if fd.revents & (libc::POLLERR | libc::POLLHUP | libc::POLLNVAL) != 0 {
            return Err(io::Error::new(
                io::ErrorKind::BrokenPipe,
                "USB device unavailable",
            ));
        }
        match device.write(remaining) {
            Ok(0) => return Err(io::ErrorKind::WriteZero.into()),
            Ok(count) => remaining = &remaining[count..],
            Err(e)
                if matches!(
                    e.kind(),
                    io::ErrorKind::WouldBlock | io::ErrorKind::Interrupted
                ) =>
            {
                continue;
            }
            Err(e) => return Err(e),
        }
    }
    Ok(())
}

#[cfg(not(target_os = "linux"))]
fn usb_write(_: &std::path::Path, _: &[u8]) -> io::Result<()> {
    Err(io::Error::new(
        io::ErrorKind::Unsupported,
        "escpos-usb requires Linux usblp",
    ))
}
