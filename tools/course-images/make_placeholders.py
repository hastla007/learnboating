#!/usr/bin/env python3
"""Platzhalter-Kursfotos aus dem Hero-Foto (Ausschnitt + Farbstimmung), bis echte Fotos vorliegen.

    python3 tools/course-images/make_placeholders.py

Echte Fotos einfach unter themes/kurswasser/assets/images/courses/<kurs>.jpg ablegen
(mind. 1800 px breit) und dieses Skript mit --from-originals laufen lassen: es erzeugt
dann <kurs>.webp (1600 px) und <kurs>-sm.webp (800 px) aus dem Original.
"""
import os
import sys
from PIL import Image, ImageEnhance

ROOT = os.path.join(os.path.dirname(__file__), '..', '..', 'themes', 'kurswasser', 'assets', 'images')
OUT = os.path.join(ROOT, 'courses')
SRC = os.path.join(ROOT, 'hero-boat.jpg')

# Kurs: (Ausschnitt x0, y0, x1, y1 im 1895×830-Foto, Farbton (r,g,b) zum Einfärben, Stärke)
CROPS = {
    'sbf-see': ((0, 0, 1895, 830), (20, 70, 120), 0.10),
    'sbf-binnen': ((420, 240, 1180, 640), (30, 110, 90), 0.18),
    'sbf-binnen-segel': ((1000, 0, 1895, 700), (20, 100, 100), 0.12),
    'src': ((1050, 0, 1700, 420), (150, 70, 50), 0.22),
    'ubi': ((300, 380, 1500, 830), (140, 90, 40), 0.22),
    'skn': ((0, 260, 1100, 740), (170, 40, 30), 0.24),
    'fkn': ((500, 0, 1500, 460), (170, 60, 30), 0.26),
    'pakete': ((200, 0, 1895, 830), (160, 120, 20), 0.16),
    'sbf-kombi': ((300, 100, 1895, 830), (20, 80, 110), 0.12),
    'kapitaen': ((700, 0, 1895, 830), (150, 110, 20), 0.18),
    'funk-kombi': ((900, 0, 1895, 560), (150, 80, 50), 0.2),
    'weitere': ((0, 200, 1300, 830), (40, 90, 110), 0.16),
    'ergaenzung': ((600, 300, 1600, 830), (30, 100, 120), 0.18),
    'paesse': ((0, 0, 1200, 600), (120, 100, 40), 0.18),
    'gruppen': ((400, 150, 1500, 700), (40, 110, 90), 0.18),
}


def save(img, slug):
    os.makedirs(OUT, exist_ok=True)
    big = img.resize((1600, int(img.height * 1600 / img.width)), Image.LANCZOS) if img.width > 1600 else img
    big.save(os.path.join(OUT, slug + '.webp'), 'WEBP', quality=78, method=6)
    small = img.resize((800, int(img.height * 800 / img.width)), Image.LANCZOS)
    small.save(os.path.join(OUT, slug + '-sm.webp'), 'WEBP', quality=74, method=6)


def placeholders():
    base = Image.open(SRC).convert('RGB')
    for slug, (box, tint, strength) in CROPS.items():
        crop = base.crop(box)
        # auf 16:7 bringen (Kopfbereich), Mitte behalten
        w, h = crop.size
        target_h = int(w * 7 / 16)
        if target_h < h:
            top = (h - target_h) // 2
            crop = crop.crop((0, top, w, top + target_h))
        crop = crop.resize((1800, int(crop.height * 1800 / crop.width)), Image.LANCZOS)
        overlay = Image.new('RGB', crop.size, tint)
        crop = Image.blend(crop, overlay, strength)
        crop = ImageEnhance.Contrast(crop).enhance(1.05)
        save(crop, slug)
        print('Platzhalter', slug)


def originals():
    for slug in CROPS:
        for ext in ('.jpg', '.jpeg', '.png'):
            p = os.path.join(OUT, slug + ext)
            if os.path.exists(p):
                save(Image.open(p).convert('RGB'), slug)
                print('Original', slug)
                break


if __name__ == '__main__':
    originals() if '--from-originals' in sys.argv else placeholders()
