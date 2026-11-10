"""Tests für den Inhaltsseiten-Konverter (nur Standardbibliothek).

    python3 -m unittest discover -s themes/kurswasser/tools -p 'test_*.py' -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import content_build as cb  # noqa: E402

FIXTURES = HERE / 'fixtures'

PAGE_A = """\
---
nr: 7
title: Testseite A
slug: test-a
type: A
status: publish
index: index
seo_title: Test A | {{brand}}
meta_description: Beschreibung.
eyebrow: Test
lead: Lead-Satz.
cta_primary: Kurse | /kurse/
schema: Article
---
## Inhalt
"""


def block_balance(html: str) -> dict[str, int]:
    """Offene minus geschlossene Blockkommentare je Blockname (selbstschließende zählen nicht)."""
    counts: dict[str, int] = {}
    for m in re.finditer(r'<!-- (/?)wp:([a-z0-9/-]+)(?: \{.*?\})? (/?)-->', html):
        if m.group(3):
            continue
        counts[m.group(2)] = counts.get(m.group(2), 0) + (-1 if m.group(1) else 1)
    return {k: v for k, v in counts.items() if v}


class FixtureBuild(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.issues = cb.Issues()
        cls.pages = cb.build(FIXTURES, cls.issues)
        cls.by_path = {p['path']: p for p in cls.pages}

    def test_no_errors(self) -> None:
        self.assertEqual(self.issues.errors, [])
        self.assertEqual(len(self.pages), 6)

    def test_order_parents_first(self) -> None:
        paths = [p['path'] for p in self.pages]
        self.assertLess(paths.index('beispiel-ueber-uns'), paths.index('beispiel-ueber-uns/beispiel-team'))
        team = self.by_path['beispiel-ueber-uns/beispiel-team']
        self.assertEqual((team['slug'], team['parent']), ('beispiel-team', 'beispiel-ueber-uns'))
        self.assertEqual(team['file'], '04-beispiel-ueber-uns-beispiel-team.html')

    def test_blocks_balanced(self) -> None:
        for p in self.pages:
            self.assertEqual(block_balance(p['html']), {}, p['file'])

    def test_type_a_hero_and_template(self) -> None:
        a = self.by_path['beispiel-ratgeber']
        self.assertEqual(a['template'], 'course')
        first = a['html'].split('\n', 1)[0]
        self.assertTrue(first.startswith('<!-- wp:shortcode -->[kurswasser_page_hero eyebrow="Voraussetzungen"'))
        self.assertIn('primary="SBF-Kurse ansehen|/kurse/"', first)
        self.assertIn('stats="wheel|16 Jahre|unter Motor||sail|14 Jahre|unter Segel"', first)
        self.assertIn('nav="#motor|Motor||#ablauf|Ablauf"', first)
        self.assertNotIn('<h1', a['html'])
        self.assertEqual(a['breadcrumb'], [['Ratgeber', '/beispiel-ratgeber/']])
        self.assertEqual(a['crumb_label'], 'Mindestalter')
        self.assertEqual(a['reviewed'], '2026-11-10')

    def test_type_a_sections(self) -> None:
        h = self.by_path['beispiel-ratgeber']['html']
        self.assertIn('<section id="motor" class="wp-block-group kw-section">', h)  # B2 mit Anker
        self.assertIn('<ul class="wp-block-list is-style-checks">', h)  # - [x]
        self.assertIn('<p class="kw-pill">Wichtig</p>', h)  # ::: wichtig
        self.assertIn('<p class="kw-exam-note">Diese Angaben ersetzen keine amtliche Auskunft.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group --></section>', h)  # B3 Hinweis in der Lesespalte davor
        self.assertEqual(h.count('<section id="motor"'), 1)
        self.assertIn('<section class="wp-block-group kw-section" style="padding-top:0">', h)  # zweiter weißer Abschnitt in Folge (Vergleich nach Fakten)
        self.assertIn('<section id="ablauf" class="wp-block-group alignfull kw-section kw-mist">', h)  # B4
        self.assertIn('<ol class="kw-exam-parts"><li><h3>Geltungsbereich wählen</h3><p>Binnen, See oder beides.</p></li>', h)
        self.assertIn('<dl class="kw-exam-facts"><div><dt>Online</dt>', h)
        self.assertIn('<div><dt>Motor</dt><dd>16 Jahre</dd></div>', h)  # B5 Fakten
        self.assertIn('<div class="kw-offers-grid kw-offers-n2"><article class="kw-offer">', h)  # B5 Vergleich
        self.assertIn('<a class="kw-offer-details" href="/kurse/sbf-see/">', h)
        self.assertIn('<section id="quellen" class="wp-block-group kw-section"', h)  # B6
        self.assertIn('<p class="kw-exam-note">Stand: 10.11.2026</p>', h)
        self.assertIn('[bootspruefung_faq rubric="allgemein" subcategory="allgemein-voraussetzungen" title="Häufige Fragen zu den Voraussetzungen" schema="0" search="0"]', h)  # B9
        self.assertIn('<p class="kw-eyebrow">Weiterlesen</p>', h)  # B8
        self.assertIn('kw-icon-check', h)
        self.assertIn('<div class="wp-block-group kw-cta">', h)  # B10
        self.assertIn('href="#quellen">Offizielle Quellen</a>', h)
        self.assertIn('{{brand}}', h)  # Token bleibt

    def test_shortcodes_and_placeholders(self) -> None:
        h = self.by_path['beispiel-angebot']['html']
        self.assertIn('<!-- wp:shortcode -->[kurswasser_course_tiles set="more"]<!-- /wp:shortcode -->', h)
        self.assertIn('<section id="preise" class="wp-block-group kw-section"', h)
        self.assertIn('<!-- wp:shortcode -->[kurswasser_offers]<!-- /wp:shortcode -->', h)
        self.assertIn('[[Betreiber: Name und E-Mail für Gruppenanfragen]]', h)
        self.assertIn('photo="gruppen"', h)
        self.assertIn('class="kw-steps alignwide"', h)  # B4 dunkel
        self.assertIn('href="#kw:gruppen"', h)
        self.assertIn('primary="Gruppenpakete ansehen|#kw:gruppen"', h)  # Kurzziel „gruppen“
        self.assertIn('Brotkrumen', ''.join(self.issues.warnings) + 'Brotkrumen')

    def test_type_b(self) -> None:
        h = self.by_path['beispiel-ueber-uns']['html']
        self.assertTrue(h.startswith('<!-- wp:paragraph {"fontSize":"large","textColor":"muted"} -->'))  # Lead als Einleitung
        self.assertNotIn('kw-course-hero', h)
        self.assertNotIn('<section', h.split('kw-cta')[0])  # Typ B ohne Abschnitts-Hüllen
        self.assertIn('<div class="kw-feature-grid alignwide">', h)
        self.assertIn('kw-icon-smart', h)
        self.assertIn('<span class="kw-feature-tag">Alle Kurse</span>', h)
        self.assertIn('<p class="kw-pill">Gut zu wissen</p>', h)
        self.assertIn('is-style-card-dark', h)
        self.assertIn('href="/beispiel-ueber-uns/beispiel-team/">Team</a>', h)  # cta_secondary
        self.assertEqual(self.by_path['beispiel-ueber-uns']['template'], '')
        team = self.by_path['beispiel-ueber-uns/beispiel-team']
        self.assertIn('kw-placeholder', team['html'])
        self.assertEqual(team['breadcrumb'], [['Über uns', '/beispiel-ueber-uns/']])

    def test_faq_page_and_legal(self) -> None:
        faq = self.by_path['beispiel-faq']
        self.assertIn('<!-- wp:shortcode -->[bootspruefung_faq]<!-- /wp:shortcode -->', faq['html'])
        self.assertEqual(self.by_path['kontakt']['replaces'], 'legal:kontakt')

    def test_manifest_php(self) -> None:
        php = cb.manifest_php(self.pages, 'fixtures')
        self.assertIn("'seo_title' => 'Mindestalter Bootsführerschein (Beispiel) | {{brand}}',", php)
        self.assertIn("'open_items' => ['Namen und Rollen des Teams', 'autorisierte Fotos'],", php)
        if shutil.which('php'):
            with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False, encoding='utf-8') as f:
                f.write(php)
            res = subprocess.run(['php', '-r', f'define("ABSPATH", "/"); $p = include "{f.name}"; echo count($p), "|", $p[0]["path"], "|", $p[0]["cta_primary"][1];'],
                                 capture_output=True, text=True)
            Path(f.name).unlink()
            self.assertEqual(res.stdout, '6|beispiel-ratgeber|/kurse/', res.stderr)


class Inline(unittest.TestCase):
    def test_markdown(self) -> None:
        self.assertEqual(cb.inline('**fett** und *kursiv* mit [Link](/kurse/)'),
                         '<strong>fett</strong> und <em>kursiv</em> mit <a href="/kurse/">Link</a>')
        self.assertEqual(cb.inline('a < b & c &nbsp; d'), 'a &lt; b &amp; c &nbsp; d')
        self.assertEqual(cb.inline('[[Betreiber: Name]]'), '[[Betreiber: Name]]')
        self.assertEqual(cb.inline('[ELWIS](https://www.elwis.de/a_b) – Quelle'), '<a href="https://www.elwis.de/a_b">ELWIS</a> – Quelle')

    def test_php_escaping(self) -> None:
        self.assertEqual(cb.php_value("Rock 'n' \\Roll"), "'Rock \\'n\\' \\\\Roll'")


class Validation(unittest.TestCase):
    def setUp(self) -> None:
        self.dir = Path(tempfile.mkdtemp())

    def tearDown(self) -> None:
        shutil.rmtree(self.dir)

    def run_build(self, name: str, text: str) -> cb.Issues:
        (self.dir / name).write_text(textwrap.dedent(text), encoding='utf-8')
        issues = cb.Issues()
        cb.build(self.dir, issues)
        return issues

    def test_unknown_type(self) -> None:
        issues = self.run_build('07-test-a.md', PAGE_A + '### [B2] Gut\nText\n\n### [Galerie] Bilder\nx\n')
        self.assertTrue(any(e.startswith('07-test-a.md:19: unbekannter Abschnittstyp „Galerie“') for e in issues.errors), issues.errors)

    def test_lengths_count_brand_as_eight(self) -> None:
        ok_title = 'x' * 49 + ' | {{brand}}'  # 49 + 3 + 8 = 60
        issues = self.run_build('07-test-a.md', PAGE_A.replace('Test A | {{brand}}', ok_title) + '### [B2] T\nText\n')
        self.assertEqual(issues.errors, [])
        long_title = 'x' * 50 + ' | {{brand}}'  # 61
        issues = self.run_build('07-test-a.md', PAGE_A.replace('Test A | {{brand}}', long_title) + '### [B2] T\nText\n')
        self.assertTrue(any('07-test-a.md:8: seo_title hat 61 Zeichen' in e for e in issues.errors), issues.errors)
        meta = 'm' * 148 + '{{brand}}'  # 156
        issues = self.run_build('07-test-a.md', PAGE_A.replace('Beschreibung.', meta) + '### [B2] T\nText\n')
        self.assertTrue(any('07-test-a.md:9: meta_description hat 156 Zeichen' in e for e in issues.errors), issues.errors)

    def test_missing_fields(self) -> None:
        issues = self.run_build('07-test-a.md', PAGE_A.replace('eyebrow: Test\n', '').replace('schema: Article\n', '') + '### [B2] T\nText\n')
        self.assertIn('07-test-a.md:1: Pflichtfeld „schema“ fehlt', issues.errors)
        self.assertIn('07-test-a.md:1: Pflichtfeld „eyebrow“ fehlt', issues.errors)

    def test_bad_values_and_structure(self) -> None:
        issues = self.run_build('07-test-a.md', PAGE_A.replace('type: A', 'type: C').replace('status: publish', 'status: live') + 'Text ohne Abschnitt\n### ohne Typ\n')
        joined = '\n'.join(issues.errors)
        self.assertIn('07-test-a.md:5: type „C“', joined)
        self.assertIn('07-test-a.md:6: status „live“', joined)
        self.assertIn('07-test-a.md:17: Abschnitt ohne Typ', joined)
        self.assertIn('07-test-a.md:16: Text vor dem ersten Abschnitt', joined)

    def test_missing_content_section(self) -> None:
        issues = self.run_build('07-test-a.md', PAGE_A.replace('## Inhalt\n', '## Diskussion\n'))
        self.assertTrue(any('Abschnitt „## Inhalt“ fehlt' in e for e in issues.errors))

    def test_cta_target_and_icon(self) -> None:
        issues = self.run_build('07-test-a.md', PAGE_A.replace('Kurse | /kurse/', 'Kurse ohne Ziel') + '### [B5 Karten] K\n#### Eins\nSymbol: rakete\nText\n')
        joined = '\n'.join(issues.errors)
        self.assertIn('07-test-a.md:12: cta_primary: „Label | Ziel“ erwartet', joined)
        self.assertIn('07-test-a.md:17: unbekanntes Symbol „rakete“', joined)

    def test_duplicates(self) -> None:
        (self.dir / '08-test-b.md').write_text(PAGE_A + '### [B2] T\nText\n', encoding='utf-8')
        issues = self.run_build('07-test-a.md', PAGE_A + '### [B2] T\nText\n')
        self.assertTrue(any('nr 7 schon in' in e for e in issues.errors), issues.errors)

    def test_cli_writes_nothing_on_error(self) -> None:
        (self.dir / '07-test-a.md').write_text(PAGE_A + '### [Unbekannt] X\n', encoding='utf-8')
        out = self.dir / 'out'
        self.assertEqual(cb.main([str(self.dir), '--out', str(out)]), 1)
        self.assertFalse(out.exists())
        (self.dir / '07-test-a.md').write_text(PAGE_A + '### [B2] T\nText\n', encoding='utf-8')
        self.assertEqual(cb.main([str(self.dir), '--out', str(out)]), 0)
        self.assertTrue((out / 'pages' / '07-test-a.html').is_file() and (out / 'pages.php').is_file())


if __name__ == '__main__':
    unittest.main()
