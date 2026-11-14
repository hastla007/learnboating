#!/usr/bin/env python3
"""Bundesländer-Karte für den Lernbereich (Prüfungsausschüsse) aus Natural Earth erzeugen.

Quelle: Natural Earth 1:10m Admin-1 (gemeinfrei), https://www.naturalearthdata.com/
Aufruf: python3 tools/maps/make_de_states.py <ne_10m_admin_1_states_provinces.geojson>
Ausgabe: bootspruefung-learning/assets/maps/de-states.json (SVG-Pfade, Projektion für Punkte)
"""
import json
import math
import os
import sys

LON0, LAT_MAX, LAT_MIN, LON_MIN, LON_MAX = 10.45, 55.1, 47.25, 5.85, 15.05
K = 100.0  # Pixel je Grad Breite
COS = math.cos(math.radians(51.2))


def proj(lon, lat):
    return ((lon - LON_MIN) * COS * K, (LAT_MAX - lat) * K)


def rdp(points, eps):
    if len(points) < 3:
        return points
    (x1, y1), (x2, y2) = points[0], points[-1]
    dx, dy = x2 - x1, y2 - y1
    norm = math.hypot(dx, dy) or 1e-9
    best, idx = 0.0, 0
    for i in range(1, len(points) - 1):
        x0, y0 = points[i]
        d = abs(dy * x0 - dx * y0 + x2 * y1 - y2 * x1) / norm
        if d > best:
            best, idx = d, i
    if best > eps:
        return rdp(points[: idx + 1], eps)[:-1] + rdp(points[idx:], eps)
    return [points[0], points[-1]]


def ring_path(ring):
    pts = [proj(lon, lat) for lon, lat in ring]
    if len(pts) > 2 and pts[0] == pts[-1]:
        pts = pts[:-1]  # geschlossener Ring: in zwei Hälften vereinfachen
    half = len(pts) // 2
    pts = rdp(pts[: half + 1], 0.6)[:-1] + rdp(pts[half:] + [pts[0]], 0.6)[:-1]
    if len(pts) < 4:
        return ''
    return 'M' + 'L'.join(f'{x:.1f} {y:.1f}' for x, y in pts) + 'Z'


def main(src):
    data = json.load(open(src, encoding='utf-8'))
    states = []
    for f in data['features']:
        p = f['properties']
        if p.get('iso_a2') != 'DE':
            continue
        g = f['geometry']
        polys = g['coordinates'] if g['type'] == 'MultiPolygon' else [g['coordinates']]
        d = ''.join(ring_path(poly[0]) for poly in polys)
        # Beschriftungspunkt: größter Ring, Mittelwert der Punkte
        big = max((poly[0] for poly in polys), key=len)
        cx = sum(proj(lon, lat)[0] for lon, lat in big) / len(big)
        cy = sum(proj(lon, lat)[1] for lon, lat in big) / len(big)
        states.append({'code': p['iso_3166_2'], 'name': p['name'], 'd': d, 'cx': round(cx, 1), 'cy': round(cy, 1)})
    states.sort(key=lambda s: s['code'])
    w, h = proj(LON_MAX, LAT_MIN)
    out = {'source': 'Natural Earth 1:10m Admin-1 (gemeinfrei)', 'viewBox': f'0 0 {w:.0f} {h:.0f}', 'states': states}
    target = os.path.join(os.path.dirname(__file__), '..', '..', 'bootspruefung-learning', 'assets', 'maps', 'de-states.json')
    json.dump(out, open(target, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
    print(len(states), 'Länder,', os.path.getsize(target), 'Bytes ->', os.path.normpath(target))


if __name__ == '__main__':
    main(sys.argv[1])
