#!/usr/bin/env python3
"""Generate a complete PWA icon set for a client from one logo file.

    python3 scripts/generate-pwa-icons.py path/to/logo.png --background '#1f2937'

Writes, relative to --output (default `public`):

    icons/icon-{72,96,128,144,152,192,256,384,512}.png   manifest, purpose "any"
    icons/icon-maskable-{192,512}.png                    manifest, purpose "maskable"
    icons/apple-touch-icon.png                           180x180, <link rel="apple-touch-icon">
    icons/favicon-{16,32}.png                            <link rel="icon">
    favicon.ico                                          16/32/48 multi-size

and prints the `icons` array to paste into the manifest.

Requires Pillow only:  pip install Pillow

Three things this handles that a naive resize does not
------------------------------------------------------
1. The logo is composited onto the flat background colour AT SOURCE RESOLUTION,
   before any resize. Resizing an RGBA image scales colour and alpha
   independently, and fully transparent pixels carry undefined colour — which
   bleeds dark halos into the edges. Blending first, scaling second is exact.

2. Every file is written opaque. Android and iOS render a transparent icon
   background as black or white depending on launcher and OS version, so a
   PNG with alpha is the usual cause of "my icon has a black box around it".

3. Maskable icons are fitted to the safe *circle*, not a square. Android crops
   a maskable icon to an arbitrary shape and only guarantees the centre 80%
   circle survives. Fitting the logo's bounding box inside a square of that
   width still lets the box corners fall outside the circle, which is how
   wordmark logos lose their last letters. Given a logo of aspect a = w/h,
   the largest box that fits a circle of diameter d is

       h = d / sqrt(a^2 + 1),   w = a * h

   because w^2 + h^2 = d^2 puts the box corners exactly on the circle. The
   result is then shrunk by MASKABLE_FILL so it does not sit flush against it.

To verify a generated maskable icon, open it at maskable.app, or check that no
non-background pixel lies further than 0.4 * size from the centre.
"""

from __future__ import annotations

import argparse
import math
import sys
from pathlib import Path

try:
    from PIL import Image
except ImportError:
    sys.exit("Pillow is required:  pip install Pillow")

# Manifest icons, purpose "any". 192 and 512 are what Chrome requires to treat
# the app as installable; the rest keep other launchers off a rescaled 512.
ANY_SIZES = (72, 96, 128, 144, 152, 192, 256, 384, 512)
MASKABLE_SIZES = (192, 512)
APPLE_SIZE = 180
FAVICON_PNG_SIZES = (16, 32)
FAVICON_ICO_SIZES = (16, 32, 48)

# Fraction of the icon each logo is allowed to occupy.
ANY_FILL = 0.84      # near full-bleed; the platform applies its own mask
APPLE_FILL = 0.80    # clear of the iOS superellipse corner radius (~0.224)
FAVICON_FILL = 0.94  # every pixel counts at 16px
SAFE_ZONE = 0.80     # Android's guaranteed maskable circle, as a fraction
MASKABLE_FILL = 0.92 # of the safe circle, leaving ~8% breathing room


def parse_hex(value: str) -> tuple[int, int, int]:
    """'#1f2937' or '#fff' -> (31, 41, 55)."""
    h = value.strip().lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    if len(h) != 6:
        raise argparse.ArgumentTypeError(f"expected a hex colour like #1f2937, got {value!r}")
    try:
        return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))  # type: ignore[return-value]
    except ValueError:
        raise argparse.ArgumentTypeError(f"{value!r} is not valid hex") from None


def flatten(logo_path: Path, background: tuple[int, int, int]) -> Image.Image:
    """Composite the logo onto the background at its native resolution."""
    logo = Image.open(logo_path).convert("RGBA")
    canvas = Image.new("RGBA", logo.size, background + (255,))
    canvas.alpha_composite(logo)
    return canvas.convert("RGB")


def render(logo: Image.Image, size: int, background: tuple[int, int, int],
           max_w: float, max_h: float) -> Image.Image:
    """Centre the logo, scaled to fit max_w x max_h, on a square icon."""
    lw, lh = logo.size
    scale = min(max_w / lw, max_h / lh)
    w, h = max(1, round(lw * scale)), max(1, round(lh * scale))
    icon = Image.new("RGB", (size, size), background)
    icon.paste(logo.resize((w, h), Image.LANCZOS), ((size - w) // 2, (size - h) // 2))
    return icon


def maskable(logo: Image.Image, size: int, background: tuple[int, int, int]) -> Image.Image:
    """Render with the logo's bounding box inscribed in the safe circle."""
    lw, lh = logo.size
    diameter = SAFE_ZONE * size * MASKABLE_FILL
    aspect = lw / lh
    box_h = diameter / math.sqrt(aspect ** 2 + 1)
    return render(logo, size, background, aspect * box_h, box_h)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Generate a PWA icon set from a client logo.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="example:\n  python3 scripts/generate-pwa-icons.py logo.png -b '#1f2937'",
    )
    parser.add_argument("logo", type=Path, help="source logo (PNG/SVG-rasterised/JPG)")
    parser.add_argument("-b", "--background", required=True, type=parse_hex,
                        help="icon background, e.g. the client's theme colour: '#1f2937'")
    parser.add_argument("-o", "--output", type=Path, default=Path("public"),
                        help="public web root to write into (default: public)")
    args = parser.parse_args()

    if not args.logo.is_file():
        return f"no such logo: {args.logo}"  # type: ignore[return-value]

    icons_dir = args.output / "icons"
    icons_dir.mkdir(parents=True, exist_ok=True)

    logo = flatten(args.logo, args.background)
    print(f"source {args.logo} ({logo.width}x{logo.height}) on #"
          f"{'%02x%02x%02x' % args.background}\n")

    def save(image: Image.Image, path: Path) -> None:
        image.save(path, "PNG", optimize=True)
        print(f"  {path}")

    for size in ANY_SIZES:
        save(render(logo, size, args.background, size * ANY_FILL, size * ANY_FILL),
             icons_dir / f"icon-{size}.png")
    for size in MASKABLE_SIZES:
        save(maskable(logo, size, args.background), icons_dir / f"icon-maskable-{size}.png")
    save(render(logo, APPLE_SIZE, args.background, APPLE_SIZE * APPLE_FILL,
                APPLE_SIZE * APPLE_FILL), icons_dir / "apple-touch-icon.png")
    for size in FAVICON_PNG_SIZES:
        save(render(logo, size, args.background, size * FAVICON_FILL, size * FAVICON_FILL),
             icons_dir / f"favicon-{size}.png")

    # Pillow builds the multi-size .ico from one image by downscaling it.
    ico_source = max(FAVICON_ICO_SIZES)
    ico_path = args.output / "favicon.ico"
    render(logo, ico_source, args.background, ico_source * FAVICON_FILL,
           ico_source * FAVICON_FILL).save(
        ico_path, "ICO", sizes=[(s, s) for s in FAVICON_ICO_SIZES])
    print(f"  {ico_path}")

    entries = [f'{{ "src": "/icons/icon-{s}.png", "sizes": "{s}x{s}", '
               f'"type": "image/png", "purpose": "any" }}' for s in ANY_SIZES]
    entries += [f'{{ "src": "/icons/icon-maskable-{s}.png", "sizes": "{s}x{s}", '
                f'"type": "image/png", "purpose": "maskable" }}' for s in MASKABLE_SIZES]
    print('\nmanifest "icons":\n[\n    ' + ',\n    '.join(entries) + '\n]')
    return 0


if __name__ == "__main__":
    sys.exit(main())
