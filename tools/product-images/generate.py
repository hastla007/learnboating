#!/usr/bin/env python3
"""Erzeugt die Produktbilder des Shop-Katalogs (1000×1000 PNG) nach
bootspruefung-learning/assets/products/<SKU>.png.

    python3 tools/product-images/generate.py

Gestaltung: Marine-Verlauf, Wellenlinien, Symbol je Produktgruppe, Titel und
Laufzeit. Keine Preise im Bild (Preise ändern sich im Shop).
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont

OUT = os.path.join(os.path.dirname(__file__), '..', '..', 'bootspruefung-learning', 'assets', 'products')
FONT_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
SIZE = 1000
# Markenname (Platzhalter, bis Name/Logo feststehen): BRAND=… python3 generate.py
BRAND = os.environ.get('BRAND', 'XXXX')

# SKU, Zeile 1 (groß), Zeile 2, Laufzeit, Symbol, Akzentfarbe
PRODUCTS = [
    ('BP-SBF-SEE-BIN', 'SBF See\n& Binnen', 'Kombikurs', '12 Monate', 'anchor', (142, 168, 255)),
    ('BP-SBF-SEE', 'SBF See', 'Einzelkurs', '12 Monate', 'anchor', (64, 196, 255)),
    ('BP-SBF-BIN', 'SBF Binnen', 'Einzelkurs', '12 Monate', 'anchor', (102, 220, 160)),
    ('BP-SRC-UBI', 'SRC & UBI', 'Funk-Kombikurs', '12 Monate', 'radio', (142, 168, 255)),
    ('BP-SRC', 'SRC', 'Funk-Vollkurs', '12 Monate', 'radio', (64, 196, 255)),
    ('BP-UBI', 'UBI', 'Funk-Vollkurs', '12 Monate', 'radio', (102, 220, 160)),
    ('BP-SBF-BIN-SEGEL', 'SBF Binnen\nunter Segel', 'Vollkurs', '12 Monate', 'sail', (102, 220, 160)),
    ('BP-SKN', 'SKN', 'Sachkunde Seenot-\nsignalmittel', '6 Monate', 'flare', (255, 120, 90)),
    ('BP-FKN', 'FKN', 'Fachkunde Seenot-\nsignalmittel', '6 Monate', 'flare', (255, 160, 90)),
    ('BP-ADD-SEE-TO-BIN', 'See → Binnen', 'Ergänzungskurs', '6 Monate', 'plus', (102, 220, 160)),
    ('BP-ADD-BIN-TO-SEE', 'Binnen → See', 'Ergänzungskurs', '6 Monate', 'plus', (64, 196, 255)),
    ('BP-ADD-BIN-SEGEL', 'Binnen →\nSegeln', 'Ergänzungskurs', '6 Monate', 'plus', (102, 220, 160)),
    ('BP-ADD-UBI-TO-SRC', 'SRC → UBI', 'Ergänzungskurs', '6 Monate', 'plus', (64, 196, 255)),
    ('BP-ADD-FKN-TO-SKN', 'FKN → SKN', 'Ergänzungskurs', '6 Monate', 'plus', (255, 140, 90)),
    ('BP-KAPITAEN', 'Kapitäns-\nBundle', 'SBF See + Binnen + Funk', '12 Monate', 'wheel', (142, 168, 255)),
    ('BP-PASS-1M', '1-Monats-\nPass', 'Alle Inhalte', '30 Tage', 'clock', (64, 196, 255)),
    ('BP-PASS-3M', '3-Monats-\nPass', 'Alle Inhalte', '90 Tage', 'clock', (247, 181, 0)),
    ('SIM-PASS-14D', 'Funkgerät-\nSimulator', '14-Tage-Sprint · SRC/UBI', '14 Tage', 'radio', (255, 120, 90)),
    ('SIM-PASS-3M', 'Funkgerät-\nSimulator', 'Standardzugang · SRC/UBI', '3 Monate', 'radio', (247, 181, 0)),
    ('SIM-PASS-12M', 'Funkgerät-\nSimulator', 'Sorglos-Zugang · SRC/UBI', '12 Monate', 'radio', (102, 220, 160)),
    ('SIM-UPGRADE-CHECKOUT', 'Simulator-\nUpgrade', 'Zum Funkkurs dazu', '12 Monate', 'radio', (64, 196, 255)),
]

# Gruppenpakete: SKU, Titel, Kurs, Personen
GROUPS = [
    ('GRP-PAIR-01', 'Crew-Ticket', 'SBF See', 2), ('GRP-PAIR-02', 'Crew-Ticket', 'SBF Binnen', 2),
    ('GRP-PAIR-03', 'Crew-Ticket', 'SBF See & Binnen', 2), ('GRP-PAIR-04', 'Crew-Ticket', 'Funk SRC & UBI', 2),
    ('GRP-FAM-01', 'Familien-\nFlotte', 'SBF See', 3), ('GRP-FAM-02', 'Familien-\nFlotte', 'SBF See & Binnen', 3),
    ('GRP-FAM-03', 'Familien-\nFlotte', 'SBF See & Binnen', 4), ('GRP-CREW-01', 'Skipper-\nCrew 5', 'SBF See', 5),
    ('GRP-CREW-02', 'Skipper-\nCrew 5', 'SBF See & Binnen', 5), ('GRP-CREW-03', 'Skipper-\nCrew 5', 'Funk SRC & UBI', 5),
    ('GRP-CREW-04', 'Yacht-\nCrew 7', 'SBF See', 7), ('GRP-CREW-05', 'Yacht-\nCrew 7', 'SBF See & Binnen', 7),
    ('GRP-CLUB-10', 'Flottille 10', 'SBF See', 10), ('GRP-CLUB-11', 'Flottille 10', 'SBF See & Binnen', 10),
    ('GRP-CLUB-20', 'Vereins-\nPaket 20', 'SBF See', 20), ('GRP-CLUB-21', 'Vereins-\nPaket 20', 'SBF See & Binnen', 20),
    ('GRP-CLUB-50', 'Partner-\nFlat 50', 'SBF See & Binnen', 50),
]
GROUP_ACCENT = {'PAIR': (255, 150, 170), 'FAM': (247, 181, 0), 'CREW': (64, 196, 255), 'CLUB': (102, 220, 160)}

NAVY_TOP = (10, 37, 64)
NAVY_BOTTOM = (4, 18, 36)
WHITE = (255, 255, 255)


def background():
    img = Image.new('RGB', (SIZE, SIZE), NAVY_TOP)
    d = ImageDraw.Draw(img)
    for y in range(SIZE):
        t = y / SIZE
        c = tuple(int(NAVY_TOP[i] * (1 - t) + NAVY_BOTTOM[i] * t) for i in range(3))
        d.line([(0, y), (SIZE, y)], fill=c)
    # Wellen unten
    for k, alpha in enumerate([60, 40, 25]):
        layer = Image.new('RGBA', (SIZE, SIZE), (0, 0, 0, 0))
        ld = ImageDraw.Draw(layer)
        base = 820 + k * 45
        pts = [(x, base + 18 * math.sin((x / SIZE) * 2 * math.pi * 2.2 + k)) for x in range(0, SIZE + 10, 10)]
        ld.polygon(pts + [(SIZE, SIZE), (0, SIZE)], fill=(120, 190, 255, alpha))
        img = Image.alpha_composite(img.convert('RGBA'), layer)
    return img


def icon(d, kind, cx, cy, r, color):
    w = 16
    if kind == 'anchor':
        d.ellipse([cx - 28, cy - r, cx + 28, cy - r + 56], outline=color, width=w)
        d.line([(cx, cy - r + 56), (cx, cy + r)], fill=color, width=w)
        d.line([(cx - 70, cy - r + 110), (cx + 70, cy - r + 110)], fill=color, width=w)
        d.arc([cx - r, cy - r + 20, cx + r, cy + r], 20, 160, fill=color, width=w)
    elif kind == 'radio':
        d.rectangle([cx - 22, cy - 10, cx + 22, cy + r], fill=color)
        for i, rr in enumerate([50, 95, 140]):
            d.arc([cx - rr, cy - 40 - rr, cx + rr, cy - 40 + rr], 200, 340, fill=color, width=w - i * 2)
    elif kind == 'sail':
        d.polygon([(cx - 10, cy - r), (cx - 10, cy + r - 40), (cx - r, cy + r - 40)], fill=color)
        d.polygon([(cx + 10, cy - r + 30), (cx + 10, cy + r - 40), (cx + r - 20, cy + r - 40)], fill=color)
        d.polygon([(cx - r, cy + r - 20), (cx + r, cy + r - 20), (cx + r - 40, cy + r + 20), (cx - r + 40, cy + r + 20)], fill=color)
    elif kind == 'flare':
        d.rectangle([cx - 22, cy, cx + 22, cy + r], fill=color)
        for a in range(0, 360, 45):
            x = cx + math.cos(math.radians(a)) * (r - 40)
            y = cy - 60 + math.sin(math.radians(a)) * (r - 40)
            d.line([(cx, cy - 60), (x, y)], fill=color, width=10)
        d.ellipse([cx - 30, cy - 90, cx + 30, cy - 30], fill=WHITE)
    elif kind == 'plus':
        d.ellipse([cx - r, cy - r, cx + r, cy + r], outline=color, width=w)
        d.line([(cx - r / 2, cy), (cx + r / 2, cy)], fill=color, width=w + 6)
        d.line([(cx, cy - r / 2), (cx, cy + r / 2)], fill=color, width=w + 6)
    elif kind == 'wheel':
        d.ellipse([cx - r + 25, cy - r + 25, cx + r - 25, cy + r - 25], outline=color, width=w)
        d.ellipse([cx - 28, cy - 28, cx + 28, cy + 28], fill=color)
        for a in range(0, 360, 45):
            x1 = cx + math.cos(math.radians(a)) * 28
            y1 = cy + math.sin(math.radians(a)) * 28
            x2 = cx + math.cos(math.radians(a)) * (r + 5)
            y2 = cy + math.sin(math.radians(a)) * (r + 5)
            d.line([(x1, y1), (x2, y2)], fill=color, width=12)
    elif kind == 'crew':
        for dx, sc in [(-80, 0.8), (80, 0.8), (0, 1.0)]:
            hr = int(38 * sc)
            x = cx + dx
            d.ellipse([x - hr, cy - 70 - hr, x + hr, cy - 70 + hr], fill=color)
            d.pieslice([x - int(75 * sc), cy - 10, x + int(75 * sc), cy + int(140 * sc)], 180, 360, fill=color)
    elif kind == 'clock':
        d.ellipse([cx - r, cy - r, cx + r, cy + r], outline=color, width=w)
        d.line([(cx, cy), (cx, cy - r + 40)], fill=color, width=w)
        d.line([(cx, cy), (cx + r - 60, cy + 30)], fill=color, width=w)


def render(sku, title, subtitle, duration, kind, accent):
    img = background()
    d = ImageDraw.Draw(img)
    size = 108 if '\n' in title else 124
    big = ImageFont.truetype(FONT_BOLD, size)
    while max(d.textlength(line, font=big) for line in title.split('\n')) > SIZE - 160 and size > 60:
        size -= 4
        big = ImageFont.truetype(FONT_BOLD, size)
    mid = ImageFont.truetype(FONT, 46)
    small = ImageFont.truetype(FONT_BOLD, 38)
    tiny = ImageFont.truetype(FONT, 30)
    d.text((80, 70), BRAND.upper(), font=small, fill=accent)
    icon(d, kind, 790, 230, 120, accent)
    d.multiline_text((80, 400), title, font=big, fill=WHITE, spacing=10)
    lines = title.count('\n') + 1
    y = 400 + lines * int(size * 1.2) + 30
    d.multiline_text((80, y), subtitle, font=mid, fill=(200, 220, 240), spacing=8)
    # Laufzeit-Plakette
    label = 'Zugang ' + duration
    tw = d.textlength(label, font=small)
    d.rounded_rectangle([80, 880, 80 + tw + 60, 950], radius=35, fill=accent)
    d.text((110, 893), label, font=small, fill=NAVY_BOTTOM)
    d.text((SIZE - 80 - d.textlength('Lernen · Üben · Prüfen', font=tiny), 905), 'Lernen · Üben · Prüfen', font=tiny, fill=WHITE)
    img.convert('RGB').save(os.path.join(OUT, sku + '.png'), optimize=True)


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    for p in PRODUCTS:
        render(*p)
    for sku, title, course, persons in GROUPS:
        accent = GROUP_ACCENT[sku.split('-')[1]]
        render(sku, title, course + '\n' + str(persons) + ' Personen', '12 Monate', 'crew', accent)
    print(len(PRODUCTS) + len(GROUPS), 'Bilder nach', os.path.normpath(OUT))
