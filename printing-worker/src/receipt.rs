use anyhow::{Result, bail};
use fontdue::{
    Font, FontSettings,
    layout::{CoordinateSystem, Layout, LayoutSettings, TextStyle},
};
use std::path::Path;

use crate::contract::Payload;

fn clean(text: &str) -> String {
    text.chars()
        .map(|c| {
            if c.is_control() || matches!(c, '\u{202a}'..='\u{202e}' | '\u{2066}'..='\u{2069}') {
                ' '
            } else {
                c
            }
        })
        .collect()
}

pub fn text(payload: &Payload) -> String {
    let mut lines = vec![
        "ΚΟΥΖΙΝΑ".to_owned(),
        format!("ΠΑΡΑΓΓΕΛΙΑ #{:03}", payload.display_number),
        format!("Αριθμός συστήματος: {}", payload.order_id),
        format!(
            "Παραγγελία: {}",
            payload
                .placed_at
                .with_timezone(&payload.timezone)
                .format("%d/%m/%Y %H:%M")
        ),
        format!(
            "Αποδοχή: {}",
            payload
                .accepted_at
                .with_timezone(&payload.timezone)
                .format("%H:%M")
        ),
        format!("Πελάτης: {}", clean(&payload.customer.name)),
        "--------------------------------".to_owned(),
    ];
    for item in &payload.items {
        lines.push(format!("{} x {}", item.quantity, clean(&item.name)));
        for option in &item.options {
            lines.push(format!(
                "  {}: {}",
                clean(&option.group),
                clean(&option.value)
            ));
        }
        if let Some(notes) = &item.notes
            && !notes.trim().is_empty()
        {
            lines.push(format!("  Σημείωση: {}", clean(notes)));
        }
        lines.push(String::new());
    }
    if payload.items.is_empty() {
        lines.push("Χωρίς καταχωρημένα είδη".to_owned());
    }
    if let Some(notes) = &payload.notes
        && !notes.trim().is_empty()
    {
        lines.push(format!("ΣΗΜΕΙΩΣΕΙΣ: {}", clean(notes)));
    }
    lines.push(format!("Job: {}", payload.print_job_id));
    lines.push(String::new());
    lines.join("\n")
}

pub struct Rasterizer {
    font: Font,
    width: usize,
    px: f32,
    cut: bool,
}

impl Rasterizer {
    pub fn new(path: &Path, width: usize, px: f32, cut: bool) -> Result<Self> {
        let data = std::fs::read(path).map_err(|_| anyhow::anyhow!("cannot read PRINTER_FONT"))?;
        let font = Font::from_bytes(data, FontSettings::default())
            .map_err(|_| anyhow::anyhow!("invalid PRINTER_FONT"))?;
        if !(128..=832).contains(&width) || !width.is_multiple_of(8) || !(16.0..=48.0).contains(&px)
        {
            bail!("invalid raster dimensions");
        }
        for c in "ΚουζίναΜαρίαΚαφέςΣημείωση".chars() {
            if font.lookup_glyph_index(c) == 0 {
                bail!("PRINTER_FONT must contain Greek glyphs");
            }
        }
        Ok(Self {
            font,
            width,
            px,
            cut,
        })
    }

    pub fn render(&self, text: &str) -> Result<Vec<u8>> {
        for c in text.chars().filter(|c| !c.is_whitespace()) {
            if self.font.lookup_glyph_index(c) == 0 {
                bail!("receipt contains a glyph unsupported by PRINTER_FONT");
            }
        }
        let mut layout = Layout::new(CoordinateSystem::PositiveYDown);
        layout.reset(&LayoutSettings {
            max_width: Some((self.width - 16) as f32),
            x: 8.0,
            y: 8.0,
            line_height: 1.2,
            ..LayoutSettings::default()
        });
        layout.append(&[&self.font], &TextStyle::new(text, self.px, 0));
        let height = (layout.height().ceil() as usize + 24).max(1);
        if height > 32_768 {
            bail!("receipt exceeds raster height limit");
        }
        let stride = self.width / 8;
        let mut bitmap = vec![0_u8; stride * height];
        for glyph in layout.glyphs() {
            let (metrics, pixels) = self.font.rasterize_config(glyph.key);
            for y in 0..metrics.height {
                for x in 0..metrics.width {
                    let dx = glyph.x as i32 + x as i32;
                    let dy = glyph.y as i32 + y as i32;
                    if dx >= 0
                        && dy >= 0
                        && (dx as usize) < self.width
                        && (dy as usize) < height
                        && pixels[y * metrics.width + x] >= 128
                    {
                        bitmap[dy as usize * stride + dx as usize / 8] |= 0x80 >> (dx as usize % 8);
                    }
                }
            }
        }
        Ok(escpos(&bitmap, stride, height, self.cut))
    }
}

fn escpos(bitmap: &[u8], stride: usize, height: usize, cut: bool) -> Vec<u8> {
    let mut output = vec![0x1b, b'@']; // Initialize, standard mode, empty line.
    // Short strips bound each command's buffer requirements. Text is only
    // bitmap data: embedded ESC/GS bytes can never become printer commands.
    for start in (0..height).step_by(128) {
        let rows = (height - start).min(128);
        output.extend_from_slice(&[
            0x1d,
            b'v',
            b'0',
            0,
            stride as u8,
            (stride >> 8) as u8,
            rows as u8,
            (rows >> 8) as u8,
        ]);
        output.extend_from_slice(&bitmap[start * stride..(start + rows) * stride]);
    }
    output.extend_from_slice(&[0x1b, b'd', 4]);
    if cut {
        output.extend_from_slice(&[0x1d, b'V', 1]);
    }
    output
}
