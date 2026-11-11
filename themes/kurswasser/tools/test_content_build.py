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
        self.assertIn('nav="#motor|Motor||#ablauf|Ablauf"', first)  # unter 5 Abschnitten: Chips im Kopf, keine Sprungleiste
        self.assertIn('stand="2026-11-10"', first)  # Stand-Zeile (Schema Article)
        self.assertNotIn('kw-toc-bar', a['html'])
        self.assertNotIn('<h1', a['html'])
        self.assertEqual(a['breadcrumb'], [['Ratgeber', '/beispiel-ratgeber/']])
        self.assertEqual(a['crumb_label'], 'Mindestalter')
        self.assertEqual(a['reviewed'], '2026-11-10')

    def test_type_a_sections(self) -> None:
        h = self.by_path['beispiel-ratgeber']['html']
        # Lauf aus Lese-Abschnitten: ein Artikel-Abschnitt (ohne Randspalte, da kein „Auf dieser Seite“)
        self.assertIn('<section class="wp-block-group kw-section kw-article-section"><!-- wp:group {"className":"kw-article"', h)
        self.assertIn('<div id="motor" class="wp-block-group kw-part">', h)  # B2 mit Anker als Teil des Artikels
        self.assertEqual(h.count('id="motor"'), 1)
        self.assertNotIn('kw-article-rail', h)
        self.assertIn('<ul class="wp-block-list is-style-checks">', h)  # - [x]
        self.assertIn('<div class="wp-block-group kw-note is-important">', h)  # ::: wichtig als Merkkasten
        self.assertIn('kw-icon-flare', h)
        self.assertIn('<p class="kw-exam-note">Diese Angaben ersetzen keine amtliche Auskunft.</p>', h)
        self.assertIn('<section id="ablauf" class="wp-block-group alignfull kw-section kw-mist">', h)  # B4 (Standardanker ablauf)
        self.assertIn('<ol class="kw-exam-parts is-route"><li><h3>Geltungsbereich wählen</h3><p>Binnen, See oder beides.</p></li>', h)  # ab 4 Schritten Kurslinie
        self.assertIn('kw-exam-grid is-sticky', h)
        self.assertIn('<dl class="kw-exam-facts"><div><dt>Online</dt>', h)
        self.assertIn('<div><dt>Motor</dt><dd>16 Jahre</dd></div>', h)  # B5 Fakten
        self.assertIn('<div class="kw-offers-grid kw-offers-n2"><article class="kw-offer">', h)  # B5 Vergleich
        self.assertIn('<section id="sbf-see-oder-sbf-binnen" class="wp-block-group alignfull kw-section kw-mist">', h)  # Vergleich als Nebelband (Rhythmus)
        self.assertIn('<a class="kw-offer-details" href="/kurse/sbf-see/">', h)
        self.assertIn('<section id="quellen" class="wp-block-group kw-section"', h)  # B6
        self.assertIn('<p class="kw-exam-note">Stand: 10.11.2026</p>', h)
        self.assertIn('[bootspruefung_faq rubric="allgemein" subcategory="allgemein-voraussetzungen" title="Häufige Fragen zu den Voraussetzungen" schema="0" search="0"]', h)  # B9 ohne ids: wie bisher
        self.assertIn('<div class="kw-more"><div class="kw-more-head"><p class="kw-eyebrow">Weiterlesen</p><h2>Passende Ratgeber</h2>', h)  # B8 als Foto-Kacheln
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
        self.assertTrue(h.startswith('<!-- wp:shortcode -->[kurswasser_page_head lead='))  # Kopf „Seekarte“ mit Einleitung
        self.assertNotIn('kw-course-hero', h)
        self.assertNotIn('<section', h.split('kw-cta')[0])  # Typ B ohne Abschnitts-Hüllen
        self.assertIn('<div class="kw-feature-grid alignwide">', h)
        self.assertIn('kw-icon-smart', h)
        self.assertIn('<span class="kw-feature-tag">Alle Kurse</span>', h)
        self.assertIn('<div class="wp-block-group kw-note">', h)
        self.assertIn('<p class="kw-eyebrow">Gut zu wissen</p>', h)
        self.assertIn('is-style-card-dark', h)
        self.assertIn('href="/beispiel-ueber-uns/beispiel-team/">Team</a>', h)  # cta_secondary
        self.assertEqual(self.by_path['beispiel-ueber-uns']['template'], 'page-info')
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
        self.assertIn("'focus_keyword' => 'Mindestalter Bootsführerschein',", php)
        self.assertEqual(php.count("'focus_keyword' => '',"), 5)  # optional: sonst leer, nie erfunden
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

    def test_focus_keyword_optional(self) -> None:
        issues = self.run_build('07-test-a.md', PAGE_A + '### [B2] T\nText\n')
        self.assertEqual(issues.errors, [])
        pages = cb.build(self.dir, cb.Issues())
        self.assertEqual(pages[0]['focus_keyword'], '')
        issues = self.run_build('07-test-a.md', PAGE_A.replace('schema: Article\n', 'schema: Article\nfocus_keyword:  Test   A \n') + '### [B2] T\nText\n')
        self.assertEqual(issues.errors, [])
        self.assertEqual(cb.build(self.dir, cb.Issues())[0]['focus_keyword'], 'Test A')
        issues = self.run_build('07-test-a.md', PAGE_A.replace('schema: Article\n', 'schema: Article\nfocus_keyword: {{brand}} Kurs\n') + '### [B2] T\nText\n')
        self.assertTrue(any('07-test-a.md:14: focus_keyword ohne {{brand}}' in e for e in issues.errors), issues.errors)
        issues = self.run_build('07-test-a.md', PAGE_A.replace('schema: Article\n', 'schema: Article\nfocus_keyword:\n  - a\n  - b\n') + '### [B2] T\nText\n')
        self.assertTrue(any('focus_keyword ist ein einzelner Begriff' in e for e in issues.errors), issues.errors)

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



class ModerationForms(unittest.TestCase):
    """Formen aus der Inhaltsmoderation, die der Konverter seit dem 10.11.2026 versteht."""

    def build(self, body: str, head: str = '') -> tuple[dict, cb.Issues]:
        d = Path(tempfile.mkdtemp())
        try:
            (d / '07-test-a.md').write_text(PAGE_A.replace('schema: Article\n', 'schema: Article\n' + head) + textwrap.dedent(body), encoding='utf-8')
            issues = cb.Issues()
            pages = cb.build(d, issues)
        finally:
            shutil.rmtree(d)
        self.assertEqual(issues.errors, [])
        return pages[0], issues

    def test_cards_from_list(self) -> None:
        pg, _ = self.build("""\
            ### [B5 Karten] Drei Wege
            - **Zuerst Binnen** (Symbol wheel, Tag „Flüsse“): Text eins. [SBF Binnen](/kurse/sbf-binnen/)
            - **Prüfung** (exam): Text zwei – Tag „Alle Kurse“
            - **Ohne Symbol:** Text drei
            """)
        html = pg['html']
        self.assertEqual(html.count('<div class="kw-feature">'), 3)
        self.assertIn('kw-icon-wheel', html)
        self.assertIn('<h3>Zuerst Binnen</h3><p>Text eins. <a href="/kurse/sbf-binnen/">SBF Binnen</a></p><span class="kw-feature-tag">Flüsse</span>', html)
        self.assertIn('<h3>Prüfung</h3><p>Text zwei</p><span class="kw-feature-tag">Alle Kurse</span>', html)
        self.assertIn('<h3>Ohne Symbol</h3><p>Text drei</p>', html)

    def test_link_items_symbol_and_photo(self) -> None:
        pg, _ = self.build("""\
            ### [B8 Weiterlesen] Weiter
            - [Eins](/eins/) (Symbol compass): Satz eins.
            - [Zwei](/zwei/) (Symbol cart) – Satz zwei.
            - [Drei](/drei/) – Satz drei.

            ### [B8 Kacheln] Kacheln
            - [Kachel](/k/) – Text {foto=pakete}
            - [Kachel 2](/k2/) – Text (Foto `sbf-see`)
            """)
        html = pg['html']
        self.assertIn('kw-icon-compass" aria-hidden="true"></span>Ratgeber</span></div><div class="kw-tile-body"><h3><a href="/eins/">Eins</a></h3><p class="kw-tile-text">Satz eins.</p>', html)
        self.assertIn('hero-boat-960.webp', html)  # Ziel ohne bekannte Seite: Standardfoto
        self.assertNotIn('%%KW', html)
        self.assertIn('kw-icon-cart', html)
        self.assertIn("courses/pakete-sm.webp", html)
        self.assertIn("courses/sbf-see-sm.webp", html)
        self.assertNotIn('(Symbol', html)
        self.assertNotIn('(Foto', html)

    def test_hero_note_steps_plain_and_stand_link(self) -> None:
        pg, _ = self.build("""\
            ### [B4 schlicht] So geht’s
            1. **Öffnen.** Text eins.
            2. **Klicken.** Text zwei.

            Danach noch ein Satz.

            ### [B6] Quellen
            - [Q](https://example.org/)

            Stand: 10.11.2026. Mehr im [Überblick](/ueberblick/).
            """, head='hero_note: "Garantiegeber: [[Betreiber: Anschrift]]"\n')
        html = pg['html']
        self.assertIn('note="Garantiegeber: &#91;&#91;Betreiber: Anschrift&#93;&#93;"', html)
        self.assertTrue(cb.has_placeholder(html))
        self.assertIn('<ol class="kw-exam-parts"><li><h3>Öffnen</h3><p>Text eins.</p></li>', html)
        self.assertNotIn('kw-mist', html)
        self.assertLess(html.index('kw-exam-parts'), html.index('Danach noch ein Satz.'))
        self.assertIn('Stand: 10.11.2026. Mehr im <a href="/ueberblick/">Überblick</a>.', html)
        self.assertEqual(pg['reviewed'], '2026-11-10')

    def test_toc_rail_and_answer(self) -> None:
        pg, issues = self.build("""\
            ### [B2] Kurz beantwortet: 16 Jahre unter Motor
            Die Antwort in einem Satz.

            ### [B2] Erster Abschnitt {#eins | Eins}
            Text eins.

            ### [B3 Wichtig] Achtung
            Symbol: shield
            Ein Hinweis.

            ### [B2] Zweiter Abschnitt
            Text zwei.

            ### [B5 Karten] Karten
            - **Eins** (Symbol exam): Text

            ### [B2] Dritter Abschnitt
            Text drei.

            ### [B9] Häufige Fragen zum Thema
            [bootspruefung_faq ids="5,6" search="0" schema="0"]

            ### [B10 CTA] Leg los
            Eyebrow: Bereit?
            Button: Kostenlos testen | /bootsscheine/
            """, head='hero_photo: gruppen flip\n')
        h = pg['html']
        first = h.split('\n', 1)[0]
        self.assertIn('photo="gruppen" flip="1"', first)
        self.assertIn('<nav class="kw-toc-bar has-rail" aria-label="Sprungleiste: Auf dieser Seite"><ol><li><a href="#kurz">Kurz beantwortet</a></li><li><a href="#eins">Eins</a></li><li><a href="#zweiter-abschnitt">Zweiter Abschnitt</a></li>', h)
        self.assertIn('<li><a href="#fragen">Häufige Fragen</a></li>', h)
        self.assertIn('kw-article has-rail', h)
        self.assertIn('<div class="kw-rail-cta"><p class="kw-eyebrow">Bereit?</p><p class="kw-rail-title">Leg los</p><a class="kw-btn kw-btn-signal" href="/bootsscheine/">Kostenlos testen</a></div>', h)
        self.assertIn('<div id="kurz" class="wp-block-group kw-answer">', h)
        self.assertIn('Kurz beantwortet</p>', h)
        self.assertIn('<h2 class="wp-block-heading">16 Jahre unter Motor</h2>', h)
        self.assertIn('<p class="kw-answer-lead">Die Antwort in einem Satz.</p>', h)
        self.assertIn('<div class="wp-block-group kw-note is-important">', h)
        self.assertIn('kw-icon-shield', h)
        self.assertIn('<h3 class="wp-block-heading">Achtung</h3>', h)
        # Rhythmus: Karten zwischen weißen Läufen als Nebelband, FAQ-Auszug mit ids als Band mit Kopf links
        self.assertIn('id="karten" class="wp-block-group alignfull kw-section kw-mist"', h)
        self.assertIn('<section id="fragen" class="wp-block-group alignfull kw-section kw-mist kw-faq-band">', h)
        self.assertIn('<p class="kw-faq-all"><a href="/faq-bootsfuehrerschein/">Alle Fragen ansehen</a></p>', h)
        self.assertIn('[bootspruefung_faq ids="5,6" search="0" schema="0"]', h)
        self.assertNotIn('title=', h.split('kw-faq-band')[1].split('<!-- /wp:shortcode -->')[0])
        self.assertIn('kw-cta kw-photo-gruppen is-flip', h)  # Abschluss-Aufruf mit dem Foto der Seite
        self.assertEqual(block_balance(h), {})
        self.assertFalse(any('Rhythmus' in w for w in issues.warnings))

    def test_tables_numbers_points_chips(self) -> None:
        pg, issues = self.build("""\
            ### [B5 Tabelle] Gebühren
            Unterschrift: Gerundete Richtwerte.
            | Prüfung | Richtwert | Zahlst du an |
            |---|---|---|
            | SBF See | rund 150 € | Prüfungsausschuss |

            ### [B5 Zahlen] Altersgrenzen
            - **15 Jahre** | SRC und UBI | Funkzeugnisse (Symbol radio, Akzent funk)
            - **18 Jahre** | SKN

            ### [B2] Liste
            - **Eins:** Text eins.
            - **Zwei:** Text zwei.
            - **Drei:** Text drei.

            Zu den Kursen: [SRC](/kurse/src/) · [UBI](/kurse/ubi/)

            | A | B | C |
            |---|---|---|
            | x | y | z |
            """)
        h = pg['html']
        self.assertIn('<figure class="kw-table"><table><caption>Gerundete Richtwerte.</caption><thead><tr><th scope="col">Prüfung</th>', h)
        self.assertIn('<tr><th scope="row">SBF See</th><td data-label="Richtwert">rund 150 €</td><td data-label="Zahlst du an">Prüfungsausschuss</td></tr>', h)
        self.assertIn('<li class="is-funk"><span class="kw-icon kw-icon-radio" aria-hidden="true"></span><strong>15<small>Jahre</small></strong><span class="kw-numbers-label">SRC und UBI</span><span class="kw-numbers-sub">Funkzeugnisse</span></li>', h)
        self.assertIn('<li><span class="kw-icon kw-icon-check" aria-hidden="true"></span><strong>18<small>Jahre</small></strong><span class="kw-numbers-label">SKN</span></li>', h)
        self.assertIn('<ul class="wp-block-list kw-points is-2col">', h)
        self.assertIn('<p class="kw-link-chips"><span class="kw-link-chips-label">Zu den Kursen:</span><a href="/kurse/src/">SRC</a><a href="/kurse/ubi/">UBI</a></p>', h)
        self.assertIn('<td data-label="B">y</td>', h)  # Tabelle ab 3 Spalten auch im Fließtext
        self.assertEqual(issues.errors, [])

    def test_glossary_selection_patterns(self) -> None:
        pg, _ = self.build("""\
            ### [B5 Glossar] A {#a}
            #### Antriebsmaschine {#antriebsmaschine}
            Amtlicher Begriff für den Motor.

            ### [B5 Glossar] B {#b}
            #### Basisfragen
            Gemeinsamer Teil.

            ### [B7 Auswahl] Welcher Kurs passt? {#kurse | Kurse}
            Kurse: sbf-see, sbf-kombi

            ### [B7] Für Bootsschulen und Vereine
            Eyebrow: Paketgrößen
            Muster: groups

            ### [B4] In drei Schritten zum Schein
            Muster: steps
            """)
        h = pg['html']
        self.assertIn('<div class="kw-glossary-letter" id="a"><h2 class="kw-glossary-mark">A</h2><div class="kw-glossary-entries"><div class="kw-glossary-entry" id="antriebsmaschine"><h3>Antriebsmaschine</h3><p>Amtlicher Begriff für den Motor.</p></div>', h)
        self.assertEqual(h.count('class="wp-block-group kw-section kw-glossary"'), 1)  # aufeinanderfolgende Buchstaben in einem Abschnitt
        self.assertIn('[kurswasser_course_tiles kurse="sbf-see,sbf-kombi"]', h)
        self.assertIn('<p class="kw-courses-all"><a href="/kurse/">Alle Kurse ansehen', h)
        self.assertIn('<!-- wp:pattern {"slug":"kurswasser/groups","kwHeading":"Für Bootsschulen und Vereine","kwEyebrow":"Paketgrößen"} /-->', h)
        self.assertIn('<!-- wp:pattern {"slug":"kurswasser/steps","kwHeading":"In drei Schritten zum Schein"} /-->', h)

    def test_type_b_head_and_tile_photos(self) -> None:
        d = Path(tempfile.mkdtemp())
        try:
            (d / '07-test-a.md').write_text(PAGE_A.replace('schema: Article\n', 'schema: Article\nhero_photo: pakete\n') + '### [B2] Text\nEin Satz.\n', encoding='utf-8')
            (d / '08-test-b.md').write_text(PAGE_A.replace('nr: 7', 'nr: 8').replace('slug: test-a', 'slug: test-b').replace('type: A', 'type: B')
                                            .replace('schema: Article\n', 'schema: WebPage\nhero_nav:\n  - "/test-a/ | Seite A | compass"\n  - "#text | Text"\n')
                                            + '### [B1]\nLead-Satz.\n\n### [B2] Text {#text}\nEin Satz.\n\n### [B8 Weiterlesen] Mehr\n- [Seite A](/test-a/) (Symbol cart) – Satz.\n', encoding='utf-8')
            issues = cb.Issues()
            pages = {p['nr']: p for p in cb.build(d, issues)}
        finally:
            shutil.rmtree(d)
        self.assertEqual(issues.errors, [])
        b = pages[8]
        self.assertEqual(b['template'], 'page-info')
        self.assertTrue(b['html'].startswith('<!-- wp:shortcode -->[kurswasser_page_head eyebrow="Test" lead="Lead-Satz." nav="/test-a/|Seite A|compass||#text|Text"]'))
        self.assertEqual(b['html'].count('Lead-Satz.'), 1)  # Einleitung nur im Kopf
        self.assertIn("courses/pakete-sm.webp", b['html'])  # Foto der Zielseite
        self.assertIn('kw-icon-cart" aria-hidden="true"></span>Test</span>', b['html'])  # Rubrik = Eyebrow der Zielseite
        self.assertNotIn('%%KW', b['html'])

    def test_unknown_course_in_selection(self) -> None:
        d = Path(tempfile.mkdtemp())
        try:
            (d / '07-test-a.md').write_text(PAGE_A + '### [B7 Auswahl] Kurse\nKurse: sbf-see, gibt-es-nicht\n', encoding='utf-8')
            issues = cb.Issues()
            cb.build(d, issues)
        finally:
            shutil.rmtree(d)
        self.assertTrue(any('gibt-es-nicht' in e for e in issues.errors))

    def test_placeholder_targets(self) -> None:
        pg, issues = self.build("""\
            ### [B10 CTA] Mitmachen
            Bewirb dich mit einem Link.
            Button: Jetzt bewerben | [[Betreiber: Bewerbungsweg]]
            Button: Partner werden | /partnerprogramm/
            """)
        self.assertIn('href="[[Betreiber: Bewerbungsweg]]"', pg['html'])
        self.assertIn('>Bewirb dich mit einem Link.</p>', pg['html'])  # Schlüsselzeilen direkt unter dem Absatz
        self.assertNotIn('Button:', pg['html'])
        self.assertIn('href="/partnerprogramm/">Partner werden</a>', pg['html'])
        self.assertTrue(any('Ziel ist ein Platzhalter' in w for w in issues.warnings))


if __name__ == '__main__':
    unittest.main()
