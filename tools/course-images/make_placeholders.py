#!/usr/bin/env python3
"""Kursfotos für Kacheln (800×350) und Kursköpfe (1600×700) als WebP erzeugen.

    python3 tools/course-images/make_placeholders.py

Reihenfolge je Bildplatz:
1. Original <kurs>.jpg im Kursordner vorhanden → Zuschnitt (Motiv leicht rechts).
2. Sonst eine Ableitung aus einem anderen Original (DERIVED: gespiegelt/gezoomt),
   damit benachbarte Kacheln nicht dasselbe Bild zeigen – bis ein eigenes Foto da ist.
3. Sonst Platzhalter aus dem Hero-Foto.
Originale werden nie überschrieben.
"""
from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageOps

ROOT = Path(__file__).resolve().parents[2]
COURSES = ROOT / 'themes' / 'kurswasser' / 'assets' / 'images' / 'courses'
HERO = ROOT / 'themes' / 'kurswasser' / 'assets' / 'images' / 'hero-boat.jpg'
TARGETS = ((1600, 700, ''), (800, 350, '-sm'))

# Alle Bildplätze des Themes (inc/courses.php)
SLUGS = ('sbf-see', 'sbf-binnen', 'sbf-binnen-segel', 'src', 'ubi', 'skn', 'fkn', 'pakete',
         'sbf-kombi', 'kapitaen', 'funk-kombi', 'weitere', 'ergaenzung', 'paesse', 'gruppen')

# Ableitungen bis eigene Fotos vorliegen: Bildplatz -> (Quelle, spiegeln, Zoom, Mittelpunkt x)
DERIVED = {
    'kapitaen': ('pakete', False, 1.0, 0.62),
    'sbf-kombi': ('sbf-see', True, 1.25, 0.45),
    'funk-kombi': ('ubi', False, 1.7, 0.74),  # Nahaufnahme Funkgerät, nicht spiegeln (Schrift)
    'weitere': ('sbf-binnen-segel', True, 1.0, 0.4),
    'ergaenzung': ('sbf-binnen', True, 1.15, 0.4),
    'paesse': ('sbf-binnen-segel', False, 1.3, 0.7),
    'gruppen': ('pakete', True, 1.2, 0.4),
}


def original(slug: str) -> Path | None:
    for ext in ('.jpg', '.jpeg', '.png'):
        p = COURSES / f'{slug}{ext}'
        if p.is_file():
            return p
    return None


def write(image: Image.Image, slug: str, centering_x: float = 0.62) -> None:
    for width, height, suffix in TARGETS:
        frame = ImageOps.fit(image, (width, height), method=Image.Resampling.LANCZOS, centering=(centering_x, 0.5))
        frame.save(COURSES / f'{slug}{suffix}.webp', 'WEBP', quality=84, method=6)


def zoom(image: Image.Image, factor: float, cx: float) -> Image.Image:
    if factor <= 1.0:
        return image
    w, h = image.size
    nw, nh = int(w / factor), int(h / factor)
    left = min(max(int(cx * w - nw / 2), 0), w - nw)
    top = (h - nh) // 2
    return image.crop((left, top, left + nw, top + nh))


def main() -> None:
    COURSES.mkdir(parents=True, exist_ok=True)
    for slug in SLUGS:
        src = original(slug)
        if src:
            write(Image.open(src).convert('RGB'), slug)
            print('Original  ', slug)
            continue
        base, mirror, factor, cx = DERIVED.get(slug, (None, False, 1.0, 0.5))
        base_path = original(base) if base else None
        if base_path:
            img = Image.open(base_path).convert('RGB')
            img = ImageOps.mirror(img) if mirror else img
            write(zoom(img, factor, 1 - cx if mirror else cx), slug, 0.5)
            print('Ableitung ', slug, '<-', base)
            continue
        write(Image.open(HERO).convert('RGB'), slug, 0.6)
        print('Platzhalter', slug)


if __name__ == '__main__':
    main()
