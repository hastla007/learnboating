#!/usr/bin/env python3
"""Inhaltsseiten-Konverter: Markdown der Moderation -> Gutenberg-Block-Markup + Manifest.

Aufruf (aus dem Repo-Wurzelordner):

    python3 themes/kurswasser/tools/content_build.py <ordner-mit-md> [--out themes/kurswasser/content] [--check]

Liest alle Dateien ``NN-slug.md`` (Dateien mit ``_`` am Anfang werden übersprungen) und schreibt

* ``<out>/pages/NN-slug.html``  – fertiges Block-Markup je Seite (``{{brand}}`` bleibt als Token),
* ``<out>/pages.php``           – Manifest (PHP-Array) für ``inc/content-pages.php``.

``--check`` prüft nur. Fehler (mit Datei und Zeile) brechen ab, ohne etwas zu schreiben; Warnungen nicht.

Format einer Seite (siehe ``content/rahmen.md``):

    ---
    nr: 21
    title: Ab welchem Alter …          (H1, Seitentitel)
    menu_title: Mindestalter
    slug: mindestalter-bootsfuehrerschein   (Unterseite: ueber-uns/team)
    parent:                            (Slug der Elternseite oder leer)
    type: A|B                          (A = Vorlage course mit Kurs-Hero, B = Vorlage page)
    status: publish|draft
    index: index|noindex
    canonical:                         (leer, Slug, /pfad/ oder URL)
    seo_title: … | {{brand}}           (≤ 60 Zeichen, {{brand}} zählt 8)
    meta_description: …                (≤ 155 Zeichen)
    focus_keyword:                     (optional; nur mit Yoast: Fokus-Keyword, wird sonst nicht gesetzt)
    eyebrow: Voraussetzungen           (Typ A Pflicht)
    lead: …                            (Typ A Pflicht; Typ B: Einleitung, falls kein [B1])
    cta_primary: SBF-Kurse ansehen | /kurse/      (Typ A Pflicht)
    cta_secondary: Quellen | #quellen
    schema: Article|WebPage|FAQPage|none
    breadcrumb: Start / Ratgeber / Mindestalter   (Mittelglieder: Titel/Menütitel einer Seite,
                                                    „Kurse“, oder „Label (/pfad/)“)
    stand: 10.11.2026                  (optional; sonst aus „Stand:“ im Quellen-Abschnitt)
    hero_photo: gruppen                (optional, Kursfoto assets/images/courses/<slot>.webp)
    hero_stats:                        (optional, Kennzahl-Kacheln: Symbol | Zahl | Label)
      - wheel | 16 Jahre | unter Motor
    hero_nav:                          (optional, Sprungnavigation: #anker | Label)
      - #motor | Motor
    hero_note: Mit kostenlosem Konto.  (optional, kurze Zeile unter den Buttons)
    replaces: legal/kontakt            (optional; Slugs der Rechtstexte werden automatisch erkannt)
    open_items:
      - …
    ---
    ## Diskussion  (wird ignoriert)
    ## Inhalt
    ### [B2] Zwischentitel {#anker}
    Text …

Abschnittstypen (Code oder Name, Variante nach dem Code): B1 Einleitung/Seitenkopf, B2 Fließtext,
B3 Hinweis (Wichtig | Gut zu wissen | <Label> = Karte, Hinweis = Fußnote, Offen = Platzhalter),
B4 Schritte (Standard: Nebelband mit Einleitung; „dunkel“ = Band wie Muster steps; „schlicht“ = ohne Band), B5 Fakten |
Vergleich | Karten (####-Karten oder Liste „- **Titel** (Symbol x): Text“), B6 Quellen, B7 Kurse/Angebote (Shortcodes, „Muster: courses“), B8 Weiterlesen |
Kacheln, B9 FAQ (Shortcode-Zeile oder „Muster: faq“), B10 CTA | Garantie. Einträge in [B8]: „- [Titel](/ziel/) – Satz {symbol}“; statt „{…}“ auch „(Symbol x)“
bzw. „(Foto x)“ hinter dem Link.

Im Text: Absätze, Listen (- / 1.), ``- [x]`` bzw. ``✓`` = Häkchenliste, **fett**, *kursiv*, [Links](/ziel/),
``#### Unterüberschrift``, ``> Zitat`` (letzte Zeile ``— Quelle`` = cite), Shortcode-Zeilen
(``[name …]`` allein auf der Zeile → wp:shortcode), Kästen ``::: wichtig`` … ``:::`` (auch ``gut zu wissen``,
``hinweis``, ``offen`` oder ein eigenes Label), Schlüsselzeilen ``Eyebrow:``, ``Symbol:``, ``Tag:``,
``Foto:``, ``Code:``, ``Button: Label | Ziel``, ``Stand: …``, ``Muster: <slug>``.
"""

from __future__ import annotations

import argparse
import html
import json
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path

BRAND_TOKEN = '{{brand}}'
BRAND_LEN = 8
MAX_SEO_TITLE = 60
MAX_META = 155
MAX_BUTTON = 28
REQUIRED = ['nr', 'title', 'slug', 'type', 'status', 'index', 'seo_title', 'meta_description', 'schema']
REQUIRED_A = ['eyebrow', 'lead', 'cta_primary']
SCHEMAS = {'Article', 'WebPage', 'FAQPage', 'AboutPage', 'ContactPage', 'none'}
LEGAL_SLUGS = {'impressum', 'datenschutz', 'agb', 'widerruf', 'kontakt'}
ICONS = {'anchor', 'arrow', 'cards', 'cart', 'check', 'clock', 'compass', 'crew', 'exam', 'flare', 'phone', 'play',
         'plus', 'radio', 'sail', 'shield', 'smart', 'user', 'wheel'}
PHOTOS = {'sbf-see', 'sbf-binnen', 'sbf-binnen-segel', 'src', 'ubi', 'skn', 'fkn', 'pakete', 'kapitaen', 'sbf-kombi',
          'funk-kombi', 'weitere', 'ergaenzung', 'paesse', 'gruppen', 'hero-boat'}
PATTERNS = {'courses', 'features', 'steps', 'groups', 'guarantee', 'faq', 'cta', 'popular', 'usp'}
KV_KEYS = {'eyebrow': 'eyebrow', 'symbol': 'icon', 'icon': 'icon', 'tag': 'tag', 'foto': 'photo', 'photo': 'photo',
           'code': 'code', 'pill': 'pill', 'button': 'button', 'stand': 'stand', 'muster': 'pattern',
           'pattern': 'pattern', 'kennzahl': 'stat', 'sprung': 'nav', 'untertitel': 'sub', 'antwort': 'answer',
           'unterschrift': 'caption', 'kurse': 'courses'}
# Kurs-Kacheln für [B7 Auswahl] (inc/courses.php: Kursdefinitionen und Zusatz-Kacheln).
COURSE_TILES = {'sbf-see', 'sbf-binnen', 'sbf-binnen-segel', 'src', 'ubi', 'skn', 'fkn', 'sbf-kombi', 'kapitaen', 'funk-kombi',
                'weitere', 'ergaenzung', 'paesse', 'gruppen'}
# Merkkästen: Standard-Symbol je Etikett; ruhige Variante (Nebel, ohne Schatten) für sachliche Hinweise.
NOTE_ICONS = {'wichtig': 'flare', 'gut zu wissen': 'compass', 'datenschutz': 'shield', 'formular': 'exam', 'transparenz': 'check',
              'im kurs': 'smart', 'tipp': 'compass'}
NOTE_QUIET = {'datenschutz', 'formular', 'transparenz', 'hinweis', 'im kurs'}
FAQ_PAGE = '/faq-bootsfuehrerschein/'
TOC_MIN = 5  # ab so vielen Abschnitten mit Überschrift: „Auf dieser Seite“ (Richtwert der Moderation)
# Name → (Code, Variante). Allgemeine Namen ohne Variante, spezielle mit.
TYPE_NAMES = {
    'seitenkopf': (1, ''), 'kopf': (1, ''), 'hero': (1, ''), 'einleitung': (1, ''), 'intro': (1, ''),
    'fließtext': (2, ''), 'fliesstext': (2, ''), 'text': (2, ''), 'abschnitt': (2, ''),
    'hinweis': (3, ''), 'hinweise': (3, ''), 'hinweis-kasten': (3, ''), 'hinweiskasten': (3, ''), 'kasten': (3, ''),
    'hinweis-kästen': (3, ''), 'wichtig': (3, 'Wichtig'), 'gut zu wissen': (3, 'Gut zu wissen'), 'offen': (3, 'Offen'),
    'fußnote': (3, 'Hinweis'), 'karte': (3, ''),
    'schritte': (4, ''), 'ablauf': (4, ''),
    'fakten': (5, 'Fakten'), 'vergleich': (5, 'Vergleich'), 'karten': (5, 'Karten'), 'fakten und vergleich': (5, ''),
    'quellen': (6, ''), 'quellen und stand': (6, ''), 'stand': (6, ''),
    'kurse': (7, ''), 'angebot': (7, ''), 'angebote': (7, ''), 'kursbausteine': (7, ''), 'kurs- und angebotsbausteine': (7, ''),
    'shop': (7, ''),
    'themenkacheln': (8, 'Kacheln'), 'kacheln': (8, 'Kacheln'), 'weiterlesen': (8, 'Weiterlesen'),
    'tabelle': (5, 'Tabelle'), 'zahlen': (5, 'Zahlen'), 'glossar': (5, 'Glossar'), 'auswahl': (7, 'Auswahl'),
    'faq': (9, ''), 'faq-auszug': (9, ''), 'häufige fragen': (9, ''),
    'abschluss': (10, ''), 'cta': (10, ''), 'cta-band': (10, ''), 'garantie': (10, 'Garantie'),
}


class BuildError(Exception):
    pass


@dataclass
class Issues:
    errors: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)


@dataclass
class Ctx:
    file: str
    page_type: str
    schema: str
    issues: Issues
    meta: dict
    reviewed: str = ''
    prev: tuple[str, bool] = ('hero', True)  # (Hintergrund, unterer Abstand) des vorigen Abschnitts
    anchors: set[str] = field(default_factory=set)
    in_article: bool = False          # Abschnitt wird als Teil eines Artikel-Laufs ohne eigene Hülle ausgegeben
    bands: dict = field(default_factory=dict)  # Abschnitt (id) → geplanter Hintergrund (Rhythmus-Regel)
    toc: list = field(default_factory=list)    # [(anker, label)] für „Auf dieser Seite“
    toc_on: bool = False
    rail_cta: dict = field(default_factory=dict)

    def err(self, line: int, msg: str) -> None:
        self.issues.errors.append(f'{self.file}:{line}: {msg}')

    def warn(self, line: int, msg: str) -> None:
        self.issues.warnings.append(f'{self.file}:{line}: {msg}')


# --------------------------------------------------------------------------- Inline-Markdown

ENTITY = re.compile(r'&(#\d+|#x[0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);')


def esc(text: str) -> str:
    """HTML-Text maskieren; vorhandene Entities (&nbsp;) bleiben."""
    out = []
    pos = 0
    for m in ENTITY.finditer(text):
        out.append(html.escape(text[pos:m.start()], quote=False))
        out.append(m.group(0))
        pos = m.end()
    out.append(html.escape(text[pos:], quote=False))
    return ''.join(out)


def esc_attr(text: str) -> str:
    return html.escape(text, quote=True)


def norm_url(url: str) -> str:
    url = url.strip()
    if url.startswith('<') and url.endswith('>'):
        url = url[1:-1]
    return url


PLACEHOLDER = '[[Betreiber:'


def url_ok(url: str) -> bool:
    """Erlaubte Linkziele; ein Platzhalter „[[Betreiber: …]]“ (auch nach mailto:) macht die Seite zum Entwurf."""
    return bool(re.match(r'^(/|#|https?://|mailto:|tel:|\[\[Betreiber:)', url))


def has_placeholder(text: str) -> bool:
    """[[Betreiber: …]] im Text oder maskiert in Shortcode-Attributen (&#91;&#91;Betreiber:)."""
    return PLACEHOLDER in text or '&#91;&#91;Betreiber:' in text


LINK = re.compile(r'\[((?:[^\[\]]|\[[^\[\]]*\])+)\]\(([^()\s]+(?:\([^()\s]*\))?[^()\s]*)\)')


def inline(text: str) -> str:
    """Fett, kursiv, Code und Links; alles andere wird maskiert. [[Betreiber: …]] bleibt Text."""
    parts: list[str] = []
    pos = 0
    for m in LINK.finditer(text):
        if text[max(0, m.start() - 1):m.start()] == '[':  # [[Betreiber: …]](…) wäre kein Link
            continue
        parts.append(_emph(text[pos:m.start()]))
        url = norm_url(m.group(2))
        parts.append(f'<a href="{esc_attr(url)}">{_emph(m.group(1))}</a>')
        pos = m.end()
    parts.append(_emph(text[pos:]))
    return ''.join(parts)


def _emph(text: str) -> str:
    out = esc(text)
    out = re.sub(r'`([^`]+)`', r'<code>\1</code>', out)
    out = re.sub(r'\*\*(?=\S)(.+?)(?<=\S)\*\*', r'<strong>\1</strong>', out)
    out = re.sub(r'__(?=\S)(.+?)(?<=\S)__', r'<strong>\1</strong>', out)
    out = re.sub(r'(?<![\w*])\*(?=\S)([^*]+?)(?<=\S)\*(?![\w*])', r'<em>\1</em>', out)
    out = re.sub(r'(?<![\w_])_(?=\S)([^_]+?)(?<=\S)_(?![\w_])', r'<em>\1</em>', out)
    return out


def plain(text: str) -> str:
    """Markdown-Auszeichnung entfernen (für Labels, Attribute)."""
    text = LINK.sub(lambda m: m.group(1), text)
    return re.sub(r'(\*\*|__|`)', '', text).strip()


# --------------------------------------------------------------------------- Block-Markup

def attrs_json(attrs: dict) -> str:
    return (' ' + json.dumps(attrs, ensure_ascii=False, separators=(',', ':'))) if attrs else ''


def p_block(inner_html: str, cls: str | None = None) -> str:
    if cls:
        return f'<!-- wp:paragraph {{"className":"{cls}"}} -->\n<p class="{cls}">{inner_html}</p>\n<!-- /wp:paragraph -->'
    return f'<!-- wp:paragraph -->\n<p>{inner_html}</p>\n<!-- /wp:paragraph -->'


def intro_block(inner_html: str) -> str:
    return ('<!-- wp:paragraph {"fontSize":"large","textColor":"muted"} -->\n'
            f'<p class="has-muted-color has-text-color has-large-font-size">{inner_html}</p>\n<!-- /wp:paragraph -->')


def h_block(inner_html: str, level: int = 2, anchor: str | None = None) -> str:
    a = {'level': level} if level != 2 else {}
    idattr = f' id="{anchor}"' if anchor else ''
    return f'<!-- wp:heading{attrs_json(a)} -->\n<h{level} class="wp-block-heading"{idattr}>{inner_html}</h{level}>\n<!-- /wp:heading -->'


def list_block(items: list, ordered: bool = False, checks: bool = False, cls: str = '') -> str:
    a: dict = {}
    if ordered:
        a['ordered'] = True
    extra = ' '.join(c for c in ['is-style-checks' if checks else '', cls] if c)
    if extra:
        a['className'] = extra
    tag = 'ol' if ordered else 'ul'
    cls_attr = 'wp-block-list' + (' ' + extra if extra else '')
    lis = []
    for item in items:
        sub = ''
        if item['children']:
            sub = list_block(item['children'], item.get('children_ordered', False))
        lis.append(f'<!-- wp:list-item --><li>{inline(item["text"])}{sub}</li><!-- /wp:list-item -->')
    return f'<!-- wp:list{attrs_json(a)} -->\n<{tag} class="{cls_attr}">{"".join(lis)}</{tag}>\n<!-- /wp:list -->'


def html_block(markup: str) -> str:
    return f'<!-- wp:html -->\n{markup}\n<!-- /wp:html -->'


def shortcode_block(code: str) -> str:
    return f'<!-- wp:shortcode -->{code}<!-- /wp:shortcode -->'


def pattern_block(slug: str) -> str:
    return f'<!-- wp:pattern {{"slug":"kurswasser/{slug}"}} /-->'


def group_block(inner: str, *, tag: str = 'div', cls: str = '', align: str = '', anchor: str = '',
                pad_top0: bool = False, layout: dict | None = None, style: dict | None = None,
                style_attr: str = '') -> str:
    a: dict = {}
    if tag != 'div':
        a['tagName'] = tag
    if align:
        a['align'] = align
    if anchor:
        a['anchor'] = anchor
    if cls:
        a['className'] = cls
    st = dict(style or {})
    css = style_attr
    if pad_top0:
        st.setdefault('spacing', {}).setdefault('padding', {})['top'] = '0'
        css = 'padding-top:0' + (';' + css if css else '')
    if st:
        a['style'] = st
    a['layout'] = layout or {'type': 'default'}
    classes = ' '.join(c for c in ['wp-block-group', f'align{align}' if align else '', cls] if c)
    idattr = f' id="{anchor}"' if anchor else ''
    sattr = f' style="{css}"' if css else ''
    return f'<!-- wp:group{attrs_json(a)} -->\n<{tag}{idattr} class="{classes}"{sattr}>{inner}</{tag}>\n<!-- /wp:group -->'


def buttons_block(btns: list[tuple[str, str, str]]) -> str:
    out = []
    for label, url, style in btns:
        a = {'className': f'is-style-{style}'} if style else {}
        cls = 'wp-block-button' + (f' is-style-{style}' if style else '')
        out.append(f'<!-- wp:button{attrs_json(a)} --><div class="{cls}"><a class="wp-block-button__link wp-element-button" '
                   f'href="{esc_attr(url)}">{esc(label)}</a></div><!-- /wp:button -->')
    return f'<!-- wp:buttons --><div class="wp-block-buttons">{"".join(out)}</div><!-- /wp:buttons -->'


TEXT_INNER = {'style': {'spacing': {'padding': {'left': '0', 'right': '0'}}}, 'layout': {'type': 'constrained', 'justifyContent': 'left'}}


def text_shell(inner: str, ctx: Ctx, anchor: str = '') -> str:
    """Typ A: weißer Abschnitt mit linksbündiger Lesespalte (B2-Hülle); im Artikel-Lauf nur ein Teil (div)."""
    if ctx.in_article:
        return group_block(inner, cls='kw-part', anchor=anchor)
    col = group_block(inner, style=TEXT_INNER['style'], layout=TEXT_INNER['layout'], style_attr='padding-right:0;padding-left:0')
    out = group_block(col, tag='section', cls='kw-section', anchor=anchor, pad_top0=white_follows(ctx))
    ctx.prev = ('white', True)
    return out


def band_shell(inner: str, ctx: Ctx, anchor: str = '', cls: str = '') -> str:
    """Nebelband über die volle Breite, Inhalt in 1240 px (Rhythmus-Regel)."""
    out = group_block(group_block(inner, align='wide'), tag='section', align='full', anchor=anchor,
                      cls='kw-section kw-mist' + (' ' + cls if cls else ''), layout={'type': 'constrained', 'wideSize': '1240px'})
    ctx.prev = ('mist', True)
    return out


def wide_shell(inner: str, ctx: Ctx, cls: str = 'kw-section', anchor: str = '') -> str:
    out = group_block(inner, tag='section', cls=cls, anchor=anchor, pad_top0=white_follows(ctx))
    ctx.prev = ('white', True)
    return out


def white_follows(ctx: Ctx) -> bool:
    return ctx.prev == ('white', True)


# --------------------------------------------------------------------------- Markdown-Blöcke

@dataclass
class Block:
    kind: str  # p, ul, ol, h4, quote, shortcode, container, kv, table
    line: int
    text: str = ''
    items: list = field(default_factory=list)
    key: str = ''
    children: list = field(default_factory=list)
    checks: bool = False


SHORTCODE_LINE = re.compile(r'^\[(?!\[)([a-z0-9_-]+)(\s[^\]]*)?\](?:.*\[/\1\])?$')
LIST_ITEM = re.compile(r'^(\s*)([-*+]|\d+[.)])\s+(.*)$')


def parse_blocks(lines: list[tuple[int, str]], ctx: Ctx) -> list[Block]:
    blocks: list[Block] = []
    i = 0
    while i < len(lines):
        no, raw = lines[i]
        line = raw.rstrip()
        s = line.strip()
        if not s:
            i += 1
            continue
        if s.startswith(':::'):
            label = s[3:].strip()
            inner: list[tuple[int, str]] = []
            i += 1
            while i < len(lines) and lines[i][1].strip() != ':::':
                inner.append(lines[i])
                i += 1
            if i >= len(lines):
                ctx.err(no, 'Kasten „:::“ ohne schließende Zeile „:::“')
            i += 1
            blocks.append(Block('container', no, text=label, children=parse_blocks(inner, ctx)))
            continue
        if s.startswith('####'):
            blocks.append(Block('h4', no, text=s.lstrip('#').strip()))
            i += 1
            continue
        if re.match(r'^#{1,3}\s', s):
            ctx.err(no, 'Überschrift # bis ### im Abschnitt nicht erlaubt (Abschnitte beginnen mit „### [Typ]“, Unterüberschriften mit ####)')
            i += 1
            continue
        if SHORTCODE_LINE.match(s):
            blocks.append(Block('shortcode', no, text=s))
            i += 1
            continue
        if s.startswith('|'):
            rows = []
            while i < len(lines) and lines[i][1].strip().startswith('|'):
                cells = [c.strip() for c in lines[i][1].strip().strip('|').split('|')]
                if not all(re.match(r'^:?-{2,}:?$', c) for c in cells):
                    rows.append(cells)
                i += 1
            blocks.append(Block('table', no, items=rows))
            continue
        if s.startswith('>'):
            q = []
            while i < len(lines) and lines[i][1].strip().startswith('>'):
                q.append(lines[i][1].strip()[1:].strip())
                i += 1
            blocks.append(Block('quote', no, items=q))
            continue
        m = LIST_ITEM.match(line)
        if m and not m.group(1):
            ordered = m.group(2)[0].isdigit()
            items: list[dict] = []
            checks = False
            while i < len(lines):
                ln = lines[i][1].rstrip()
                mm = LIST_ITEM.match(ln)
                if mm and not mm.group(1) and mm.group(2)[0].isdigit() == ordered:
                    text = mm.group(3).strip()
                    cm = re.match(r'^(\[[xX ]\]|✓|✔)\s*(.*)$', text)
                    if cm:
                        checks = True
                        text = cm.group(2)
                    items.append({'text': text, 'children': [], 'line': lines[i][0]})
                elif mm and mm.group(1) and items:
                    items[-1]['children'].append({'text': mm.group(3).strip(), 'children': []})
                    items[-1]['children_ordered'] = mm.group(2)[0].isdigit()
                elif ln.startswith((' ', '\t')) and ln.strip() and items:
                    target = items[-1]['children'][-1] if items[-1]['children'] else items[-1]
                    target['text'] += ' ' + ln.strip()
                else:
                    break
                i += 1
            blocks.append(Block('ol' if ordered else 'ul', no, items=items, checks=checks))
            continue
        kv = re.match(r'^([A-Za-zÄÖÜäöüß]+):\s*(.*)$', s)
        if kv and kv.group(1).lower() in KV_KEYS:
            blocks.append(Block('kv', no, key=KV_KEYS[kv.group(1).lower()], text=kv.group(2).strip()))
            i += 1
            continue
        para = [s]
        i += 1
        while i < len(lines):
            nxt = lines[i][1].strip()
            if not nxt or nxt.startswith((':::', '#', '>', '|')) or LIST_ITEM.match(lines[i][1].rstrip()) or SHORTCODE_LINE.match(nxt):
                break
            kvn = re.match(r'^([A-Za-zÄÖÜäöüß]+):\s', nxt)
            if kvn and kvn.group(1).lower() in KV_KEYS:
                break  # Schlüsselzeile (Button:, Stand: …) direkt unter einem Absatz
            para.append(nxt)
            i += 1
        blocks.append(Block('p', no, text=' '.join(para)))
    return blocks


def take_kv(blocks: list[Block], key: str) -> list[Block]:
    found = [b for b in blocks if b.kind == 'kv' and b.key == key]
    for b in found:
        blocks.remove(b)
    return found


def split_target(spec: str, ctx: Ctx, line: int, what: str) -> tuple[str, str] | None:
    if '|' not in spec:
        ctx.err(line, f'{what}: „Label | Ziel“ erwartet, gefunden „{spec}“')
        return None
    label, target = (x.strip() for x in spec.rsplit('|', 1))
    target = norm_url(target)
    if re.match(r'^[a-z0-9-]+(#[a-z0-9-]+)?$', target):
        target = '#kw:' + target
    if target.startswith(PLACEHOLDER):
        ctx.warn(line, f'{what}: Ziel ist ein Platzhalter „{target}“ – Seite bleibt Entwurf')
    if not label or not url_ok(target):
        ctx.err(line, f'{what}: ungültiges Ziel „{target}“ (interne URL /…/, #anker, #kw:ziel oder https://…)')
        return None
    if len(label) > MAX_BUTTON:
        ctx.warn(line, f'{what}: Button-Text „{label}“ hat {len(label)} Zeichen (empfohlen ≤ {MAX_BUTTON})')
    return label, target


def render_generic(blocks: list[Block], ctx: Ctx, h3_level: int = 3) -> str:
    """Absätze, Listen, Unterüberschriften, Zitate, Kästen, Shortcodes und Schlüsselzeilen im Text."""
    out: list[str] = []
    for b in blocks:
        if b.kind == 'p':
            out.append(chips_paragraph(b.text) or p_block(inline(b.text)))
        elif b.kind in ('ul', 'ol'):
            out.append(list_block(b.items, b.kind == 'ol', b.checks, points_class(b)))
        elif b.kind == 'h4':
            text, anchor = split_anchor(b.text)
            out.append(h_block(inline(text), h3_level, anchor))
        elif b.kind == 'shortcode':
            out.append(shortcode_block(b.text))
        elif b.kind == 'quote':
            lines = b.items
            cite = ''
            if len(lines) > 1 and re.match(r'^(—|–|--)\s*', lines[-1]):
                cite = re.sub(r'^(—|–|--)\s*', '', lines[-1])
                lines = lines[:-1]
            paras = p_block(inline(' '.join(lines))) if lines else ''
            cite_html = f'<cite>{inline(cite)}</cite>' if cite else ''
            out.append(f'<!-- wp:quote -->\n<blockquote class="wp-block-quote">{paras}{cite_html}</blockquote>\n<!-- /wp:quote -->')
        elif b.kind == 'container':
            out.append(render_container(b, ctx))
        elif b.kind == 'table':
            out.append(html_block(table_html(b, ctx) if any(len(r) > 2 for r in b.items) else facts_html_from_table(b, ctx)))
        elif b.kind == 'kv':
            if b.key == 'stand':
                out.append(p_block('Stand: ' + inline(b.text), 'kw-exam-note'))
                remember_stand(b.text, ctx)
            elif b.key == 'button':
                t = split_target(b.text, ctx, b.line, 'Button')
                if t:
                    out.append(buttons_block([(t[0], t[1], 'outline')]))
            elif b.key == 'pattern':
                out.append(pattern_markup(b, ctx))
            elif b.key == 'caption':
                out.append(p_block(inline(b.text), 'kw-exam-note'))
            else:
                ctx.warn(b.line, f'Zeile „{b.key}:“ hat hier keine Wirkung – als Text ausgegeben')
                out.append(p_block(inline(b.text)))
    return '\n'.join(out)


def pattern_markup(b: Block, ctx: Ctx, heading: str = '', eyebrow: str = '', lead: str = '') -> str:
    """Muster einbinden; Überschrift, Eyebrow und Lead des Abschnitts ersetzen die festen Texte des Musters
    (Attribute kwHeading/kwEyebrow/kwLead, eingesetzt von inc/content-pages.php)."""
    slug = b.text.strip().removeprefix('kurswasser/')
    if slug not in PATTERNS:
        ctx.err(b.line, f'unbekanntes Muster „{b.text}“ (erlaubt: {", ".join(sorted(PATTERNS))})')
        return ''
    if slug == 'cta':
        ctx.warn(b.line, 'Muster cta hat feste Texte – besser [B10 CTA] mit eigenen Texten')
    ctx.prev = {'features': ('mist', True), 'groups': ('mist', True), 'steps': ('dark', True), 'guarantee': ('white', False)}.get(slug, ('white', True))
    attrs: dict = {'slug': f'kurswasser/{slug}'}
    for key, value in (('kwHeading', heading), ('kwEyebrow', eyebrow), ('kwLead', lead)):
        if value.strip():
            attrs[key] = plain(value.strip())
    return f'<!-- wp:pattern {json.dumps(attrs, ensure_ascii=False, separators=(",", ":"))} /-->'


def remember_stand(text: str, ctx: Ctx) -> None:
    m = re.search(r'(\d{1,2})\.(\d{1,2})\.(\d{4})', text)
    iso = re.search(r'(\d{4})-(\d{2})-(\d{2})', text)
    if ctx.reviewed:
        return
    if m:
        ctx.reviewed = f'{m.group(3)}-{int(m.group(2)):02d}-{int(m.group(1)):02d}'
    elif iso:
        ctx.reviewed = iso.group(0)


def facts_html_from_table(b: Block, ctx: Ctx, answer: bool = False) -> str:
    rows = b.items
    if not rows or any(len(r) != 2 for r in rows):
        ctx.err(b.line, 'zweispaltige Tabelle erwartet (Merkmal | Wert → Faktenliste); für mehr Spalten [B5 Tabelle] nutzen')
        return ''
    rows = rows[1:] if len(rows) > 1 else rows  # Kopfzeile
    return facts_dl([(r[0], r[1]) for r in rows], ctx, b.line, answer)


def facts_dl(pairs: list[tuple[str, str]], ctx: Ctx, line: int, answer: bool = False) -> str:
    out = []
    for dt, dd in pairs:
        dt = plain(dt).rstrip(':')
        if len(dt) > 14 and not answer:
            ctx.warn(line, f'Fakten: Merkmal „{dt}“ hat {len(dt)} Zeichen (Spalte 8 rem, empfohlen ≤ 14)')
        value = inline(dd)
        if answer:
            value = re.sub(r'^(\d+(?:[.,]\d+)?)(\s)', r'<b>\1</b>\2', value, count=1)  # führende Zahl groß
        out.append(f'<div><dt>{esc(dt)}</dt><dd>{value}</dd></div>')
    return f'<dl class="kw-exam-facts">{"".join(out)}</dl>'


def table_html(b: Block, ctx: Ctx, caption: str = '') -> str:
    """Echte Tabelle (Kopfzeile, erste Spalte als Zeilenkopf); am Handy gestapelt (data-label)."""
    rows = b.items
    if len(rows) < 2:
        ctx.err(b.line, 'Tabelle braucht eine Kopfzeile und mindestens eine Zeile')
        return ''
    head, body = rows[0], rows[1:]
    if any(len(r) != len(head) for r in body):
        ctx.err(b.line, f'Tabelle: alle Zeilen brauchen {len(head)} Zellen wie die Kopfzeile')
        return ''
    thead = '<thead><tr>' + ''.join(f'<th scope="col">{inline(c)}</th>' for c in head) + '</tr></thead>'
    trs = []
    for r in body:
        cells = [f'<th scope="row">{inline(r[0])}</th>']
        cells += [f'<td data-label="{esc_attr(plain(head[i]))}">{inline(c)}</td>' for i, c in enumerate(r) if i]
        trs.append('<tr>' + ''.join(cells) + '</tr>')
    cap = f'<caption>{inline(caption)}</caption>' if caption else ''
    return f'<figure class="kw-table"><table>{cap}{thead}<tbody>{"".join(trs)}</tbody></table></figure>'


POINT_ITEM = re.compile(r'^\*\*[^*]{1,60}?:?\*\*:?\s')
CHAIN = re.compile(r'^(?P<pre>.*?)(?P<links>\[[^\]]+\]\([^)\s]+\)(?:\s*·\s*\[[^\]]+\]\([^)\s]+\))+)\s*\.?$')


def points_class(b: Block) -> str:
    """Liste, deren Einträge alle mit „**Begriff:**“ beginnen (ab 3): Begriff als eigene Zeile, ab Desktop zweispaltig."""
    if b.kind != 'ul' or b.checks or len(b.items) < 3 or not all(POINT_ITEM.match(it['text']) for it in b.items):
        return ''
    return 'kw-points' + (' is-2col' if all(len(plain(it['text'])) <= 230 for it in b.items) else '')


def chips_paragraph(text: str) -> str:
    """„… [A](/a/) · [B](/b/) · [C](/c/)“ → Linkkette als Chips (größere Tippziele)."""
    m = CHAIN.match(text.strip())
    if not m:
        return ''
    links = re.findall(r'\[[^\]]+\]\([^)\s]+\)', m.group('links'))
    pre = m.group('pre').strip()
    inner = (f'<span class="kw-link-chips-label">{inline(pre)}</span>' if pre else '') + ''.join(inline(x) for x in links)
    return p_block(inner, 'kw-link-chips')


def kv_pairs(items: list[dict]) -> list[tuple[str, str]] | None:
    pairs = []
    for it in items:
        m = re.match(r'^\*\*(.+?):?\*\*:?\s*(.+)$', it['text']) or re.match(r'^([^:]{1,40}):\s+(.+)$', it['text'])
        if not m:
            return None
        pairs.append((m.group(1), m.group(2)))
    return pairs


def render_container(b: Block, ctx: Ctx) -> str:
    label = b.text.strip()
    key = label.lower().replace('-', ' ')
    if key in ('hinweis', 'note', 'fußnote', 'fussnote'):
        return '\n'.join(p_block(inline(c.text), 'kw-exam-note') for c in b.children if c.kind == 'p')
    if key in ('offen', 'platzhalter', 'redaktionell offen'):
        return open_box(list(b.children), ctx)
    children = list(b.children)
    icon = (take_kv(children, 'icon') or [Block('kv', 0)])[0].text
    label = {'wichtig': 'Wichtig', 'gut zu wissen': 'Gut zu wissen', '': 'Gut zu wissen', 'karte': ''}.get(key, label)
    return note_block(label, '', children, ctx, icon, b.line)


def note_block(label: str, sub: str, blocks: list[Block], ctx: Ctx, icon: str = '', line: int = 0) -> str:
    """Merkkasten: Symbol-Kachel, Etikett, optionaler Titel, Text. „Wichtig“ mit gelber Kante."""
    key = label.lower()
    cls = 'kw-note' + (' is-important' if key == 'wichtig' else '') + (' is-quiet' if key in NOTE_QUIET else '')
    icon = check_icon(icon, ctx, line, NOTE_ICONS.get(key, 'check'))
    inner = html_block(f'<span class="kw-note-icon"><span class="kw-icon kw-icon-{icon}" aria-hidden="true"></span></span>')
    inner += '\n' + (p_block(esc(label), 'kw-eyebrow') + '\n' if label else '')
    inner += (h_block(inline(sub), 3) + '\n' if sub else '') + render_generic(blocks, ctx)
    return group_block(inner, cls=cls)


def open_box(blocks: list[Block], ctx: Ctx) -> str:
    """Kasten „Offen“: bündelt, was der Betreiber noch liefern muss (Seite bleibt Entwurf)."""
    ctx.meta['has_placeholder_box'] = True
    inner = p_block('Offen – vor der Veröffentlichung', 'kw-eyebrow') + '\n' + render_generic(blocks, ctx)
    return group_block(inner, cls='kw-note kw-placeholder is-open')


ANCHOR = re.compile(r'^(.*?)\s*\{#([a-z0-9-]+)(?:\s*\|\s*((?:[^{}]|\{\{brand\}\})*?))?\s*\}\s*$')


def split_anchor(text: str) -> tuple[str, str | None]:
    text, anchor, _ = split_anchor_label(text)
    return text, anchor


def split_anchor_label(text: str) -> tuple[str, str | None, str]:
    """„Titel {#anker}“ bzw. „Titel {#anker | Kurzlabel}“ (Kurzlabel für „Auf dieser Seite“)."""
    m = ANCHOR.match(text)
    return (m.group(1), m.group(2), (m.group(3) or '').strip()) if m else (text, None, '')


def slugify(text: str) -> str:
    t = plain(text).lower()
    for a, b in (('ä', 'ae'), ('ö', 'oe'), ('ü', 'ue'), ('ß', 'ss')):
        t = t.replace(a, b)
    t = re.sub(r'[^a-z0-9]+', '-', t).strip('-')
    return t[:40].rstrip('-') or 'abschnitt'


# --------------------------------------------------------------------------- Abschnitte

@dataclass
class Section:
    code: int
    variant: str
    heading: str
    anchor: str | None
    line: int
    blocks: list[Block]
    label: str = ''  # Kurzlabel für „Auf dieser Seite“ ({#anker | Label})


def section_type(token: str) -> tuple[int, str] | None:
    token = token.strip()
    m = re.match(r'^B\s?(\d{1,2})(?:-[AB])?(?:\+\S*)?\s*[:–-]?\s*(.*)$', token, re.I)
    if m:
        code = int(m.group(1))
        return (code, m.group(2).strip()) if 1 <= code <= 10 else None
    key = token.lower()
    if key in TYPE_NAMES:
        return TYPE_NAMES[key]
    first = key.split()[0] if key else ''
    if first in TYPE_NAMES:
        code, variant = TYPE_NAMES[first]
        return code, (token[len(first):].strip() or variant)
    return None


TEXT_SHELL_END = '</div>\n<!-- /wp:group --></section>\n<!-- /wp:group -->'


def is_reading(sec: Section) -> bool:
    """Lese-Abschnitte, die zu einem Artikel-Lauf zusammengefasst werden (Fließtext, Hinweis, Fakten, Tabelle, Zahlen)."""
    if sec.code in (2, 6):
        return not any((b.kind == 'kv' and b.key == 'pattern') or b.kind == 'shortcode' for b in sec.blocks)
    if sec.code == 3:
        return True
    if sec.code == 5:
        v = sec.variant.lower()
        return v.startswith(('fakten', 'tabelle', 'zahlen')) or (not v and not any(b.kind == 'h4' for b in sec.blocks))
    return False


def is_answer(sec: Section) -> bool:
    return sec.code in (2, 5) and bool(re.match(r'^kurz beantwortet\b', sec.heading.strip(), re.I))


def is_glossary(sec: Section) -> bool:
    return sec.code == 5 and sec.variant.lower().startswith('glossar')


def toc_label(sec: Section, nav_labels: dict[str, str]) -> str:
    if sec.label:
        return sec.label
    if sec.anchor and sec.anchor in nav_labels:
        return nav_labels[sec.anchor]
    if is_answer(sec):
        return 'Kurz beantwortet'
    if sec.code == 9:
        return 'Häufige Fragen'
    return plain(sec.heading).rstrip('.?!:') if not sec.heading.endswith('?') else plain(sec.heading)


def toc_candidate(s: Section) -> bool:
    """Abschnitte mit H2 und Anker im Markup (Muster und FAQ-Auszüge ohne ids haben keinen Anker)."""
    pattern = any(b.kind == 'kv' and b.key == 'pattern' for b in s.blocks)
    if not s.heading or s.code not in (2, 4, 5, 7, 9) or pattern:
        return False
    if s.code == 7:
        return any(b.kind == 'shortcode' or (b.kind == 'kv' and b.key == 'courses') for b in s.blocks)
    if s.code == 9:
        return any(b.kind == 'shortcode' and ' ids="' in b.text for b in s.blocks)
    return True


def plan_page(sections: list[Section], ctx: Ctx) -> None:
    """Anker, „Auf dieser Seite“, Hintergründe (Rhythmus) und Mini-CTA der Randspalte planen (nur Typ A)."""
    nav_labels = {}
    for item in ctx.meta.get('hero_nav', []):
        parts = [x.strip() for x in item.split('|')]
        if len(parts) >= 2 and parts[0].startswith('#'):
            nav_labels[parts[0][1:]] = parts[1]
    used = {s.anchor for s in sections if s.anchor}
    glossary = [s for s in sections if is_glossary(s)]
    if glossary:
        cands = glossary
    else:
        cands = [s for s in sections if toc_candidate(s)]
    for s in cands:
        if not s.anchor:
            offers = s.code == 7 and any(b.kind == 'shortcode' and b.text.startswith('[kurswasser_offers') for b in s.blocks)
            base = 'kurz' if is_answer(s) else 'fragen' if s.code == 9 else 'ablauf' if s.code == 4 else 'preise' if offers else slugify(s.heading)
            a, n = base, 2
            while a in used:
                a, n = f'{base}-{n}', n + 1
            s.anchor = a
            used.add(a)
    mode = str(ctx.meta.get('toc_mode', 'auto')).lower()
    ctx.toc_on = mode in ('an', 'on', 'ja') or (mode == 'auto' and len(cands) >= TOC_MIN)
    ctx.toc = [(s.anchor, toc_label(s, nav_labels)) for s in cands]
    if ctx.toc_on:
        for a, label in ctx.toc:
            if len(label) > 38:
                ctx.warn(1, f'„Auf dieser Seite“: Label „{label}“ ist lang – Kurzlabel mit {{#{a} | …}} setzen')
    # Rhythmus: Bänder fest (Schritte, FAQ, Muster features/groups), Karten/Vergleich als Nebelband, wenn kein Band daneben.
    fixed = []
    for s in sections:
        pat = next((b.text.strip() for b in s.blocks if b.kind == 'kv' and b.key == 'pattern'), '')
        if s.code == 4 and not s.variant.lower().startswith(('dunkel', 'dark', 'steps', 'schlicht', 'ohne', 'weiß', 'weiss')) and not pat:
            fixed.append('mist')
        elif s.code == 4 and (s.variant.lower().startswith(('dunkel', 'dark', 'steps')) or pat == 'steps'):
            fixed.append('dark')
        elif s.code == 9 and not pat:
            fixed.append('mist')
        elif pat in ('features', 'groups'):
            fixed.append('mist')
        else:
            fixed.append('')
    for i, s in enumerate(sections):
        if s.code == 5 and s.variant.lower().startswith(('karten', 'optionen', 'vergleich')) or (s.code == 5 and not s.variant and any(b.kind == 'h4' for b in s.blocks)):
            neighbours = fixed[i - 1:i] + fixed[i + 1:i + 2]
            ctx.bands[id(s)] = 'white' if 'mist' in neighbours else 'mist'
            fixed[i] = ctx.bands[id(s)] if ctx.bands[id(s)] == 'mist' else ''
        if s.code == 8 and not s.variant.lower().startswith(('kachel', 'hub')):
            ctx.bands[id(s)] = 'white' if fixed[i - 1:i] == ['mist'] else 'mist'
            fixed[i] = ctx.bands[id(s)] if ctx.bands[id(s)] == 'mist' else ''
    # Mini-CTA der Randspalte: Eyebrow, Überschrift und erster Knopf des Abschluss-Aufrufs.
    cta = next((s for s in sections if s.code == 10 and not s.variant.lower().startswith('garantie') and s.heading), None)
    if cta is not None:
        eb = next((b.text for b in cta.blocks if b.kind == 'kv' and b.key == 'eyebrow'), '')
        btn = next((b.text for b in cta.blocks if b.kind == 'kv' and b.key == 'button' and '|' in b.text), '')
        if not btn and ctx.meta.get('cta_primary'):
            btn = '|'.join(ctx.meta['cta_primary'])
        if btn:
            label, target = (x.strip() for x in btn.rsplit('|', 1))
            if re.match(r'^[a-z0-9-]+(#[a-z0-9-]+)?$', target):
                target = '#kw:' + target
            ctx.rail_cta = {'eyebrow': eb, 'title': cta.heading, 'label': label, 'url': target}


def toc_items_html(items: list[tuple[str, str]]) -> str:
    return '<ol>' + ''.join(f'<li><a href="#{esc_attr(a)}">{esc(label)}</a></li>' for a, label in items) + '</ol>'


def toc_bar(ctx: Ctx, has_rail: bool) -> str:
    """Klebende Sprungleiste unter dem Kopf (am Handy immer; am Desktop nur ohne Randspalte)."""
    if not ctx.toc_on or not ctx.toc:
        return ''
    cls = 'kw-toc-bar' + (' has-rail' if has_rail else '') + (' is-letters' if all(len(lbl) <= 2 for _, lbl in ctx.toc) else '')
    return html_block(f'<nav class="{cls}" aria-label="Sprungleiste: Auf dieser Seite">{toc_items_html(ctx.toc)}</nav>')


def rail_html(ctx: Ctx) -> str:
    out = '<aside class="kw-article-rail">'
    out += f'<nav class="kw-toc" aria-label="Auf dieser Seite"><p class="kw-eyebrow">Auf dieser Seite</p>{toc_items_html(ctx.toc)}</nav>'
    c = ctx.rail_cta
    if c:
        out += ('<div class="kw-rail-cta">' + (f'<p class="kw-eyebrow">{esc(c["eyebrow"])}</p>' if c['eyebrow'] else '')
                + f'<p class="kw-rail-title">{inline(c["title"])}</p><a class="kw-btn kw-btn-signal" href="{esc_attr(c["url"])}">{esc(c["label"])}</a></div>')
    return out + '</aside>'


def plain_text_part(sec: Section) -> bool:
    """Fließtext-Abschnitt ohne visuelles Element (für die Rhythmus-Warnung)."""
    return sec.code == 2 and not is_answer(sec) and not any(b.kind in ('ul', 'ol', 'table', 'quote', 'container', 'shortcode') for b in sec.blocks)


def render_run(run: list[Section], ctx: Ctx, rail: bool) -> str:
    """Artikel: mehrere Lese-Abschnitte in einer Lesespalte; optional Randspalte mit „Auf dieser Seite“ und Mini-CTA."""
    if len(run) == 1 and not rail:
        return RENDERERS[run[0].code](run[0], ctx)
    pad0 = white_follows(ctx)
    streak = 0
    for sec in run:
        streak = streak + 1 if plain_text_part(sec) else 0
        if streak == 3:
            ctx.warn(sec.line, 'Rhythmus: drei Fließtext-Abschnitte ohne visuelles Element hintereinander – Liste, Merkkasten, Tabelle oder Band einbauen')
    ctx.in_article = True
    try:
        parts = [RENDERERS[sec.code](sec, ctx) for sec in run]
    finally:
        ctx.in_article = False
    main = group_block('\n'.join(p for p in parts if p), cls='kw-article-main')
    art = group_block(main + ('\n' + html_block(rail_html(ctx)) if rail else ''), cls='kw-article' + (' has-rail' if rail else ''))
    out = group_block(art, tag='section', cls='kw-section kw-article-section', pad_top0=pad0)
    ctx.prev = ('white', True)
    return out


def render_glossary(run: list[Section], ctx: Ctx) -> str:
    letters = []
    for sec in run:
        pre, subs = subsections(list(sec.blocks))
        entries = []
        for h, body in subs:
            title, anchor = split_anchor(h.text)
            if anchor:
                ctx.anchors.add(anchor)
            text = ''.join(f'<p>{inline(b.text)}</p>' if b.kind == 'p' else (html_list(b) if b.kind in ('ul', 'ol') else '') for b in body)
            entries.append(f'<div class="kw-glossary-entry"{f" id={chr(34)}{anchor}{chr(34)}" if anchor else ""}><h3>{inline(title)}</h3>{text}</div>')
        intro = ''.join(f'<p>{inline(b.text)}</p>' for b in pre if b.kind == 'p')
        letters.append(f'<div class="kw-glossary-letter"{f" id={chr(34)}{sec.anchor}{chr(34)}" if sec.anchor else ""}><h2 class="kw-glossary-mark">{inline(sec.heading)}</h2>'
                       f'<div class="kw-glossary-entries">{intro}{"".join(entries)}</div></div>')
    out = group_block(html_block(''.join(letters)), tag='section', cls='kw-section kw-glossary', pad_top0=white_follows(ctx))
    ctx.prev = ('white', True)
    return out


def html_list(b: Block) -> str:
    tag = 'ol' if b.kind == 'ol' else 'ul'
    return f'<{tag}>' + ''.join(f'<li>{inline(it["text"])}</li>' for it in b.items) + f'</{tag}>'


def render_sections(sections: list[Section], ctx: Ctx) -> str:
    if ctx.page_type != 'A':
        return '\n\n'.join(h for h in (RENDERERS[sec.code](sec, ctx) for sec in sections) if h)
    plan_page(sections, ctx)
    # Segmente: Läufe aus Lese-Abschnitten, Glossar-Läufe, sonst einzelne Abschnitte.
    segs: list[tuple[str, list[Section]]] = []
    for sec in sections:
        kind = 'gloss' if is_glossary(sec) else ('read' if is_reading(sec) else 'one')
        if kind != 'one' and segs and segs[-1][0] == kind:
            segs[-1][1].append(sec)
        else:
            segs.append((kind, [sec]))
    # Randspalte nur am Seitenanfang (erster Inhaltsabschnitt ist ein Lauf aus ≥ 2 Lese-Abschnitten); sonst klebt die
    # Sprungleiste auf allen Breiten – ein Inhaltsverzeichnis weit unten auf der Seite hilft niemandem.
    rail_idx = -1
    first_content = next((i for i, (k, r) in enumerate(segs) if not (k == 'one' and r[0].code == 1)), 0)
    if ctx.toc_on and segs and segs[first_content][0] == 'read' and len(segs[first_content][1]) >= 2:
        rail_idx = first_content
    ctx.meta['has_rail_top'] = rail_idx >= 0  # Desktop: Randspalte statt Sprungleiste
    out: list[str] = []
    prev_code = 0
    for i, (kind, run) in enumerate(segs):
        if kind == 'gloss':
            html_ = render_glossary(run, ctx)
        elif kind == 'read':
            html_ = render_run(run, ctx, rail=i == rail_idx)
        else:
            sec = run[0]
            if sec.code == 10 and (sec.variant.lower().startswith('garantie') or (sec.heading.lower().startswith('garantie') and not sec.blocks)) and prev_code == 7:
                ctx.warn(sec.line, 'Garantie-Box direkt nach Kurs-/Angebotskacheln – die Leiste dort nennt die Garantie schon (einmal pro Seite, an der Entscheidungsstelle)')
            html_ = RENDERERS[sec.code](sec, ctx)
        prev_code = run[-1].code
        if html_:
            out.append(html_)
    return '\n\n'.join(out)


def head_parts(sec: Section, blocks: list[Block], ctx: Ctx) -> tuple[str, str]:
    eb = take_kv(blocks, 'eyebrow')
    return (eb[0].text if eb else ''), sec.heading


def r_b1(sec: Section, ctx: Ctx) -> str:
    blocks = list(sec.blocks)
    for b in take_kv(blocks, 'stat'):
        ctx.meta.setdefault('hero_stats', []).append(b.text)
    for b in take_kv(blocks, 'nav'):
        ctx.meta.setdefault('hero_nav', []).append(b.text)
    for b in take_kv(blocks, 'photo'):
        ctx.meta['hero_photo'] = b.text
    if ctx.page_type == 'A':
        if blocks:
            ctx.err(sec.line, 'Typ A: Der Seitenkopf entsteht aus dem Front-Matter (eyebrow, lead, cta_*). In [B1] nur „Kennzahl:“, „Sprung:“, „Foto:“')
        return ''
    # Typ B: Einleitung steht im Kopf „Seekarte“ (lead); weitere Absätze bleiben als Einleitung im Inhalt.
    out = []
    for b in blocks:
        if b.kind == 'p' and plain(b.text) == plain(str(ctx.meta.get('head_lead', ''))):
            continue
        if b.kind == 'p' and not ctx.meta.get('head_lead'):
            ctx.meta['head_lead'] = b.text
            continue
        out.append(intro_block(inline(b.text)) if b.kind == 'p' else render_generic([b], ctx))
    ctx.meta['has_intro'] = True
    return '\n'.join(out)


def answer_title(sec: Section, blocks: list[Block]) -> str:
    ans = take_kv(blocks, 'answer')
    if ans:
        return ans[0].text
    m = re.match(r'^kurz beantwortet\s*[:–—-]\s*(.+)$', sec.heading.strip(), re.I)
    return m.group(1).strip() if m else ''


def answer_box(sec: Section, title: str, body: str, ctx: Ctx) -> str:
    """„Kurz beantwortet“: Nebel, blaue Kante, Antwort-H2, darunter Text bzw. Fakten als Kacheln."""
    head = (p_block('<span class="kw-icon kw-icon-check" aria-hidden="true"></span>Kurz beantwortet', 'kw-eyebrow') + '\n' + h_block(inline(title))) \
        if title else h_block('Kurz beantwortet')
    anchor = sec.anchor or ('kurz' if 'kurz' not in ctx.anchors else '')
    ctx.anchors.add(anchor)
    box = group_block(head + '\n' + body, cls='kw-answer', anchor=anchor if ctx.page_type == 'A' else '')
    if ctx.page_type == 'B':
        return box
    if ctx.in_article:
        return box
    return text_shell(box, ctx)


def r_b2(sec: Section, ctx: Ctx) -> str:
    blocks = list(sec.blocks)
    if is_answer(sec):
        title = answer_title(sec, blocks)
        body = []
        for n, b in enumerate(blocks):
            if b.kind == 'p' and not body:
                body.append(p_block(inline(b.text), 'kw-answer-lead'))
            else:
                body.append(render_generic([b], ctx))
        return answer_box(sec, title, '\n'.join(body), ctx)
    eyebrow, heading = head_parts(sec, blocks, ctx)
    inner = (p_block(esc(eyebrow), 'kw-eyebrow') + '\n' if eyebrow else '')
    inner += (h_block(inline(heading)) + '\n' if heading else '') + render_generic(blocks, ctx)
    if ctx.page_type == 'B':
        return inner if not sec.anchor else _anchor_first_heading(inner, sec.anchor)
    return text_shell(inner, ctx, sec.anchor or '')


def _anchor_first_heading(inner: str, anchor: str) -> str:
    return inner.replace('<h2 class="wp-block-heading">', f'<h2 class="wp-block-heading" id="{anchor}">', 1)


def r_b3(sec: Section, ctx: Ctx) -> str:
    key = sec.variant.strip().lower()
    blocks = list(sec.blocks)
    if key in ('hinweis', 'note', 'fußnote', 'fussnote'):
        inner = '\n'.join(p_block(inline(b.text), 'kw-exam-note') if b.kind == 'p' else render_generic([b], ctx) for b in blocks)
    elif key in ('offen', 'platzhalter'):
        inner = open_box(blocks, ctx)
    else:
        icon = (take_kv(blocks, 'icon') or [Block('kv', 0)])[0].text
        if key in ('', 'karte', 'kasten'):
            label, sub = (sec.heading or 'Gut zu wissen'), ''
        else:
            label = {'wichtig': 'Wichtig', 'gut zu wissen': 'Gut zu wissen'}.get(key, sec.variant.strip())
            sub = sec.heading if sec.heading and sec.heading.lower() != label.lower() else ''
        inner = note_block(label, sub, blocks, ctx, icon, sec.line)
    if ctx.page_type == 'B':
        return inner
    return text_shell(inner, ctx, sec.anchor or '')


def step_items(blocks: list[Block], ctx: Ctx, line: int) -> tuple[list[tuple[str, str]], list[Block]]:
    """Schritte aus einer nummerierten Liste („**Titel** – Text“ / „Titel: Text“) oder aus ####-Abschnitten."""
    steps: list[tuple[str, str]] = []
    rest: list[Block] = []
    ol = next((b for b in blocks if b.kind == 'ol'), None)
    if ol is not None:
        for it in ol.items:
            t = it['text']
            m = re.match(r'^\*\*(.+?)\*\*\s*[:–—-]?\s*(.*)$', t) or re.match(r'^([^:–—]{2,60})\s*[:–—]\s+(.*)$', t)
            if m:
                steps.append((m.group(1).strip().rstrip(':.').rstrip(), m.group(2).strip()))
            else:
                steps.append((t, ''))
                ctx.warn(it.get('line', line), 'Schritt ohne Titel – „**Titel** – Text“ empfohlen')
        rest = [b for b in blocks if b is not ol]
        return steps, rest
    cur = None
    for b in blocks:
        if b.kind == 'h4':
            cur = [b.text, []]
            steps.append(cur)  # type: ignore[arg-type]
        elif cur is not None and b.kind == 'p':
            cur[1].append(b.text)
        else:
            rest.append(b)
    steps = [(t, ' '.join(ps)) for t, ps in steps]  # type: ignore[misc]
    if not steps:
        ctx.err(line, '[B4] braucht eine nummerierte Liste oder ####-Schritte')
    return steps, rest


def r_b4(sec: Section, ctx: Ctx) -> str:
    blocks = list(sec.blocks)
    pattern = take_kv(blocks, 'pattern')
    if pattern:
        eb = take_kv(blocks, 'eyebrow')
        return pattern_markup(pattern[0], ctx, sec.heading, eb[0].text if eb else '')
    eyebrow, heading = head_parts(sec, blocks, ctx)
    steps, rest = step_items(blocks, ctx, sec.line)
    parts = ''.join(f'<li><h3>{inline(t)}</h3>' + (f'<p>{inline(x)}</p>' if x else '') + '</li>' for t, x in steps)
    route = len(steps) >= 4
    ol = f'<ol class="kw-exam-parts{" is-route" if route else ""}">{parts}</ol>'
    anchor = sec.anchor or ('ablauf' if 'ablauf' not in ctx.anchors else '')
    ctx.anchors.add(anchor)
    if ctx.page_type == 'B':
        inner = (h_block(inline(heading), 2, sec.anchor) + '\n' if heading else '') + render_generic(rest, ctx)
        return inner + '\n' + html_block(ol)
    if sec.variant.lower() in ('schlicht', 'ohne band', 'weiß', 'weiss'):
        # Schritte in der weißen Lesespalte (ohne Nebelband), Text danach unter der Liste.
        inner = (h_block(inline(heading)) + '\n' if heading else '') + html_block(ol)
        inner += ('\n' + render_generic(rest, ctx)) if rest else ''
        return text_shell(inner, ctx, anchor)
    if sec.variant.lower() in ('dunkel', 'dark', 'steps') and not rest:
        head = (p_block(esc(eyebrow), 'kw-eyebrow') if eyebrow else '') + (h_block(inline(heading)) if heading else '')
        steps_html = ''.join(f'<li class="kw-step"><h3>{inline(t)}</h3><p>{inline(x)}</p></li>' for t, x in steps)
        if len(steps) != 3:
            ctx.warn(sec.line, f'[B4 dunkel] ist für 3 Schritte gestaltet, gefunden {len(steps)}')
        inner = group_block(head, cls='kw-section-head', align='wide') + '\n' + html_block(f'<ol class="kw-steps alignwide">{steps_html}</ol>')
        ctx.prev = ('dark', True)
        return group_block(inner, tag='section', align='full', anchor=anchor, cls='kw-section kw-dark', layout={'type': 'constrained', 'wideSize': '1240px'})
    left = (p_block(esc(eyebrow), 'kw-eyebrow') + '\n' if eyebrow else '') + (h_block(inline(heading)) + '\n' if heading else '')
    first = True
    extra_html = ''
    for b in rest:
        if b.kind == 'p' and first:
            left += p_block(inline(b.text), 'kw-lead') + '\n'
            first = False
        elif b.kind == 'ul' and (pairs := kv_pairs(b.items)):
            extra_html += facts_dl(pairs, ctx, b.line)
        elif b.kind == 'container' and b.text.lower() in ('hinweis', 'note'):
            extra_html += ''.join(f'<p class="kw-exam-note">{inline(c.text)}</p>' for c in b.children if c.kind == 'p')
        else:
            left += render_generic([b], ctx) + '\n'
    if extra_html:
        left += html_block(extra_html)
    grid = group_block(group_block(left.strip(), cls='kw-exam-head') + '\n' + html_block(ol), cls='kw-exam-grid' + (' is-sticky' if route else ''), align='wide')
    ctx.prev = ('mist', True)
    return group_block(grid, tag='section', align='full', anchor=anchor, cls='kw-section kw-mist', layout={'type': 'constrained', 'wideSize': '1240px'})


def r_b5(sec: Section, ctx: Ctx) -> str:
    blocks = list(sec.blocks)
    variant = sec.variant.lower()
    h4s = [b for b in blocks if b.kind == 'h4']
    if not variant:
        variant = ('vergleich' if len(h4s) == 2 else 'karten') if h4s else 'fakten'
    if variant.startswith('vergleich'):
        return b5_compare(sec, blocks, ctx)
    if variant.startswith('karten') or variant.startswith('optionen'):
        return b5_cards(sec, blocks, ctx)
    if variant.startswith('tabelle'):
        return b5_table(sec, blocks, ctx)
    if variant.startswith('zahlen'):
        return b5_numbers(sec, blocks, ctx)
    if variant.startswith('glossar'):
        return render_glossary([sec], ctx) if ctx.page_type == 'A' else ''
    if not variant.startswith('fakten'):
        ctx.err(sec.line, f'[B5] Variante „{sec.variant}“ unbekannt (Fakten, Vergleich, Karten, Tabelle, Zahlen, Glossar)')
        return ''
    answer = is_answer(sec)
    title = answer_title(sec, blocks) if answer else ''
    eyebrow, heading = head_parts(sec, blocks, ctx)
    inner = '' if answer else (p_block(esc(eyebrow), 'kw-eyebrow') + '\n' if eyebrow else '') + (h_block(inline(heading)) + '\n' if heading else '')
    found = False
    pending_html = ''
    first_p = True
    for b in blocks:
        if b.kind == 'ul' and (pairs := kv_pairs(b.items)):
            pending_html += facts_dl(pairs, ctx, b.line, answer)
            found = True
        elif b.kind == 'table':
            pending_html += facts_html_from_table(b, ctx, answer)
            found = True
        elif b.kind == 'container' and b.text.lower() in ('hinweis', 'note') and pending_html:
            pending_html += ''.join(f'<p class="kw-exam-note">{inline(c.text)}</p>' for c in b.children if c.kind == 'p')
        else:
            if pending_html:
                inner += html_block(pending_html) + '\n'
                pending_html = ''
            if answer and b.kind == 'p' and first_p:
                inner += p_block(inline(b.text), 'kw-answer-lead') + '\n'
                first_p = False
            else:
                inner += render_generic([b], ctx) + '\n'
    if pending_html:
        inner += html_block(pending_html)
    if not found:
        ctx.err(sec.line, '[B5 Fakten] braucht eine Liste „- Merkmal: Wert“ (oder eine zweispaltige Tabelle)')
    inner = inner.strip()
    if answer:
        return answer_box(sec, title, inner, ctx)
    if ctx.page_type == 'B':
        return _anchor_first_heading(inner, sec.anchor) if sec.anchor else inner
    return text_shell(inner, ctx, sec.anchor or '')


def section_head(sec: Section, blocks: list[Block], ctx: Ctx) -> str:
    eyebrow, heading = head_parts(sec, blocks, ctx)
    out = (p_block(esc(eyebrow), 'kw-eyebrow') + '\n' if eyebrow else '') + (h_block(inline(heading)) + '\n' if heading else '')
    if ctx.page_type == 'B' and sec.anchor:
        out = _anchor_first_heading(out, sec.anchor)
    return out


def b5_table(sec: Section, blocks: list[Block], ctx: Ctx) -> str:
    """[B5 Tabelle]: echte Tabelle (auch zweispaltig), „Unterschrift:“ als Tabellenunterschrift."""
    cap = take_kv(blocks, 'caption')
    inner = section_head(sec, blocks, ctx)
    tables = [b for b in blocks if b.kind == 'table']
    if not tables:
        ctx.err(sec.line, '[B5 Tabelle] braucht eine Tabelle „| Kopf | Kopf |“')
    for b in blocks:
        if b.kind == 'table':
            inner += html_block(table_html(b, ctx, cap[0].text if cap else '')) + '\n'
        else:
            inner += render_generic([b], ctx) + '\n'
    inner = inner.strip()
    if ctx.page_type == 'B':
        return inner
    return text_shell(inner, ctx, sec.anchor or '')


NUMBER_ITEM = re.compile(r'^\*\*(?P<num>.+?)\*\*\s*\|\s*(?P<label>[^|]+?)(?:\s*\|\s*(?P<sub>[^|]+?))?\s*(?:\((?P<opts>[^()]*)\))?\s*$')
ACCENTS = {'funk': 'is-funk', 'binnen': 'is-binnen', 'signal': 'is-signal', 'seenot': 'is-signal', 'see': '', 'kombi': 'is-kombi'}


def b5_numbers(sec: Section, blocks: list[Block], ctx: Ctx) -> str:
    """[B5 Zahlen]: Kacheln „- **15 Jahre** | Label | Unterzeile (Symbol radio, Akzent funk)“ – nur Zahlen aus dem eigenen Text."""
    inner = section_head(sec, blocks, ctx)
    found = False
    for b in blocks:
        if b.kind == 'ul' and all(NUMBER_ITEM.match(it['text']) for it in b.items):
            lis = []
            for it in b.items:
                m = NUMBER_ITEM.match(it['text'])
                opts = dict(re.findall(r'(Symbol|Akzent)\s+([a-z-]+)', m.group('opts') or ''))
                icon = check_icon(opts.get('Symbol', ''), ctx, it.get('line', b.line), 'check')
                accent = ACCENTS.get(opts.get('Akzent', 'see'), '')
                num = plain(m.group('num'))
                nm = re.match(r'^([\d.,–-]+\s?%?)\s*(.*)$', num)
                big = f'{esc(nm.group(1).strip())}<small>{esc(nm.group(2))}</small>' if nm and nm.group(2) else esc(num)
                lis.append(f'<li{f" class={chr(34)}{accent}{chr(34)}" if accent else ""}><span class="kw-icon kw-icon-{icon}" aria-hidden="true"></span>'
                           f'<strong>{big}</strong><span class="kw-numbers-label">{inline(m.group("label"))}</span>'
                           + (f'<span class="kw-numbers-sub">{inline(m.group("sub"))}</span>' if m.group('sub') else '') + '</li>')
            inner += html_block('<ul class="kw-numbers">' + ''.join(lis) + '</ul>') + '\n'
            found = True
        else:
            inner += render_generic([b], ctx) + '\n'
    if not found:
        ctx.err(sec.line, '[B5 Zahlen] braucht eine Liste „- **Zahl Einheit** | Label | Unterzeile (Symbol x, Akzent funk)“')
    inner = inner.strip()
    if ctx.page_type == 'B':
        return inner
    return text_shell(inner, ctx, sec.anchor or '')


def subsections(blocks: list[Block]) -> tuple[list[Block], list[tuple[Block, list[Block]]]]:
    pre: list[Block] = []
    subs: list[tuple[Block, list[Block]]] = []
    for b in blocks:
        if b.kind == 'h4':
            subs.append((b, []))
        elif subs:
            subs[-1][1].append(b)
        else:
            pre.append(b)
    return pre, subs


def b5_compare(sec: Section, blocks: list[Block], ctx: Ctx) -> str:
    eyebrow, heading = head_parts(sec, blocks, ctx)
    pre, subs = subsections(blocks)
    if len(subs) != 2:
        ctx.err(sec.line, f'[B5 Vergleich] braucht genau zwei ####-Karten, gefunden {len(subs)}')
    arts = []
    for h, body in subs:
        sub = take_kv(body, 'sub')
        subtitle = sub[0].text if sub else ''
        lst = ''
        details = ''
        paras = ''
        for b in body:
            if b.kind == 'ul':
                lst += ''.join(f'<li>{inline(it["text"])}</li>' for it in b.items)
            elif b.kind == 'p' and (lm := re.fullmatch(r'\[([^\]]+)\]\(([^)]+)\)', b.text.strip())):
                details = f'<div class="kw-offer-actions"><a class="kw-offer-details" href="{esc_attr(norm_url(lm.group(2)))}">{esc(lm.group(1))}</a></div>'
            elif b.kind == 'p' and not subtitle and not lst:
                subtitle = b.text
            elif b.kind == 'p':
                paras += f'<p>{inline(b.text)}</p>'
            else:
                ctx.warn(b.line, 'In einer Vergleichskarte nur Untertitel, Liste und ein Link')
        arts.append(f'<article class="kw-offer"><header><h3>{inline(h.text)}</h3>'
                    + (f'<p class="kw-offer-sub">{inline(subtitle)}</p>' if subtitle else '') + '</header>'
                    + paras + (f'<ul class="kw-offer-list">{lst}</ul>' if lst else '') + details + '</article>')
    lead = ' '.join(b.text for b in pre if b.kind == 'p')
    grid = f'<div class="kw-offers-grid kw-offers-n2{" alignwide" if ctx.page_type == "B" else ""}">{"".join(arts)}</div>'
    if ctx.page_type == 'B':
        head = (h_block(inline(heading), 2, sec.anchor) + '\n' if heading else '') + (p_block(inline(lead)) + '\n' if lead else '')
        return head + html_block(grid)
    head = '<div class="kw-center-head">' + (f'<p class="kw-eyebrow">{esc(eyebrow)}</p>' if eyebrow else '') \
        + (f'<h2>{inline(heading)}</h2>' if heading else '') + (f'<p class="kw-lead">{inline(lead)}</p>' if lead else '') + '</div>'
    if ctx.bands.get(id(sec)) == 'mist':
        return band_shell(html_block(head + '\n' + grid), ctx, anchor=sec.anchor or '')
    return wide_shell(html_block(head + '\n' + grid), ctx, anchor=sec.anchor or '')


def feature_html(icon: str, title_html: str, text_html: str, tag: str = '') -> str:
    return (f'<div class="kw-feature"><span class="kw-feature-icon"><span class="kw-icon kw-icon-{icon}" aria-hidden="true"></span></span>'
            f'<h3>{title_html}</h3>' + (f'<p>{text_html}</p>' if text_html else '') + (f'<span class="kw-feature-tag">{esc(tag)}</span>' if tag else '') + '</div>')


def check_icon(icon: str, ctx: Ctx, line: int, default: str) -> str:
    icon = icon.strip().lower()
    if not icon:
        return default
    if icon not in ICONS:
        ctx.err(line, f'unbekanntes Symbol „{icon}“ (vorhanden: {", ".join(sorted(ICONS))})')
        return default
    return icon


CARD_ITEM = re.compile(r'^\*\*(.+?)\*\*\s*(?:\((?:Symbol\s+)?`?([a-z]+)`?(?:,\s*Tag\s*[„"]([^“"]+)[“"])?\))?\s*[:–—-]?\s*(.*)$')


def list_cards(blocks: list[Block]) -> tuple[list[Block], list[tuple[Block, list[Block]]]]:
    """Karten aus Listeneinträgen „- **Titel** (Symbol x): Text“ (statt ####-Abschnitten)."""
    pre: list[Block] = []
    subs: list[tuple[Block, list[Block]]] = []
    for b in blocks:
        if b.kind == 'ul' and b.items and all(CARD_ITEM.match(it['text']) for it in b.items):
            for it in b.items:
                m = CARD_ITEM.match(it['text'])
                text = m.group(4).strip()
                tag = m.group(3) or ''
                tm = re.search(r'\s*[–—-]\s*Tag\s*[„"]([^“"]+)[“"]\s*$', text)
                if tm:
                    text, tag = text[:tm.start()].strip(), tm.group(1)
                body = [Block('p', it.get('line', b.line), text=text)] if text else []
                if tag:
                    body.append(Block('kv', it.get('line', b.line), key='tag', text=tag))
                if m.group(2):
                    body.append(Block('kv', it.get('line', b.line), key='icon', text=m.group(2)))
                subs.append((Block('h4', it.get('line', b.line), text=m.group(1).strip().rstrip(':')), body))
        else:
            pre.append(b)
    return pre, subs


def b5_cards(sec: Section, blocks: list[Block], ctx: Ctx, defaults: tuple[str, ...] = ('anchor', 'compass', 'sail', 'wheel', 'smart', 'exam')) -> str:
    eyebrow, heading = head_parts(sec, blocks, ctx)
    pre, subs = subsections(blocks)
    if not subs:
        pre, subs = list_cards(pre)
    if not subs:
        ctx.err(sec.line, '[B5 Karten] braucht ####-Karten (Titel, „Symbol:“, „Tag:“, Text) oder eine Liste „- **Titel** (Symbol x): Text“')
    cards = []
    for n, (h, body) in enumerate(subs):
        icon = check_icon((take_kv(body, 'icon') or [Block('kv', 0)])[0].text, ctx, h.line, defaults[n % len(defaults)])
        tag = (take_kv(body, 'tag') or [Block('kv', 0)])[0].text
        text = ' '.join(inline(b.text) for b in body if b.kind == 'p')
        cards.append(feature_html(icon, inline(h.text), text, tag))
    lead = ' '.join(b.text for b in pre if b.kind == 'p')
    grid = f'<div class="kw-feature-grid{" alignwide" if ctx.page_type == "B" else ""}">' + ''.join(cards) + '</div>'
    if ctx.page_type == 'B':
        return (h_block(inline(heading), 2, sec.anchor) + '\n' if heading else '') + (p_block(inline(lead)) + '\n' if lead else '') + html_block(grid)
    head = (p_block(esc(eyebrow), 'kw-eyebrow') if eyebrow else '') + (h_block(inline(heading)) if heading else '') + (p_block(inline(lead), 'kw-lead') if lead else '')
    inner = (group_block(head, cls='kw-section-head') + '\n' if head else '') + html_block(grid)
    if ctx.bands.get(id(sec)) == 'mist':
        return band_shell(inner, ctx, anchor=sec.anchor or '')
    return wide_shell(inner, ctx, anchor=sec.anchor or '')


def r_b6(sec: Section, ctx: Ctx) -> str:
    blocks = list(sec.blocks)
    heading = sec.heading or 'Quellen und offizielle Stellen'
    anchor = sec.anchor or 'quellen'
    if not any(b.kind == 'kv' and b.key == 'stand' for b in blocks) and not ctx.meta.get('stand'):
        ctx.warn(sec.line, 'Quellen ohne „Stand: TT.MM.JJJJ“')
    inner = h_block(inline(heading), 2, anchor if ctx.page_type == 'B' else None) + '\n' + render_generic(blocks, ctx)
    if ctx.page_type == 'B':
        return inner
    return text_shell(inner, ctx, anchor)


def r_b7(sec: Section, ctx: Ctx) -> str:
    blocks = list(sec.blocks)
    pattern = take_kv(blocks, 'pattern')
    courses = take_kv(blocks, 'courses')
    if pattern and all(b.kind in ('p', 'kv') for b in blocks) and not any(b.kind == 'kv' and b.key != 'eyebrow' for b in blocks):
        eb = take_kv(blocks, 'eyebrow')
        lead = ' '.join(b.text for b in blocks if b.kind == 'p')
        return pattern_markup(pattern[0], ctx, sec.heading, eb[0].text if eb else '', lead)
    eyebrow, heading = head_parts(sec, blocks, ctx)
    if courses or sec.variant.lower().startswith('auswahl'):
        return b7_selection(sec, blocks, courses, eyebrow, heading, ctx)
    codes = [b for b in blocks if b.kind == 'shortcode']
    if not codes and not pattern:
        ctx.err(sec.line, '[B7] braucht eine Shortcode-Zeile (z. B. [kurswasser_offers]), „Kurse: …“ oder „Muster: courses“')
        return ''
    lead = ' '.join(b.text for b in blocks if b.kind == 'p')
    others = [b for b in blocks if b.kind not in ('shortcode', 'p')]
    out = []
    if pattern:
        out.append(pattern_markup(pattern[0], ctx, heading, eyebrow, lead))
        heading = lead = eyebrow = ''
    for b in codes:
        name = re.match(r'^\[([a-z0-9_-]+)', b.text).group(1)
        if ctx.page_type == 'B':
            out.append((h_block(inline(heading)) + '\n' if heading else '') + (p_block(inline(lead)) + '\n' if lead else '') + shortcode_block(b.text))
            heading = lead = ''
            continue
        if name == 'kurswasser_course_tiles':
            head = '<div class="kw-courses-head"><div>' + (f'<p class="kw-eyebrow">{esc(eyebrow)}</p>' if eyebrow else '') \
                + (f'<h2 class="wp-block-heading">{inline(heading)}</h2>' if heading else '') + '</div>' \
                + (f'<p class="kw-lead">{inline(lead)}</p>' if lead else '') + '</div>'
            inner = (html_block(head) + '\n' if (heading or lead or eyebrow) else '') + shortcode_block(b.text)
            out.append(wide_shell(inner, ctx, cls='kw-section kw-courses', anchor=sec.anchor or ''))
        else:
            anchor = sec.anchor or ('preise' if name == 'kurswasser_offers' and 'preise' not in ctx.anchors else '')
            ctx.anchors.add(anchor)
            head = ''
            if heading or lead or eyebrow:
                head = html_block('<div class="kw-center-head">' + (f'<p class="kw-eyebrow">{esc(eyebrow)}</p>' if eyebrow else '')
                                  + (f'<h2>{inline(heading)}</h2>' if heading else '') + (f'<p class="kw-lead">{inline(lead)}</p>' if lead else '') + '</div>') + '\n'
            out.append(wide_shell(head + shortcode_block(b.text), ctx, anchor=anchor))
        heading = lead = eyebrow = ''
    if others:
        out.append(text_shell(render_generic(others, ctx), ctx) if ctx.page_type == 'A' else render_generic(others, ctx))
    return '\n\n'.join(out)


def b7_selection(sec: Section, blocks: list[Block], courses: list[Block], eyebrow: str, heading: str, ctx: Ctx) -> str:
    """[B7 Auswahl]: 2–3 passende Kurse („Kurse: sbf-see, sbf-binnen“) plus Link „Alle Kurse ansehen“."""
    slugs = [x.strip() for x in (courses[0].text if courses else '').split(',') if x.strip()]
    if not slugs:
        ctx.err(sec.line, '[B7 Auswahl] braucht „Kurse: sbf-see, sbf-binnen“')
        return ''
    for slug in slugs:
        if slug not in COURSE_TILES:
            ctx.err(sec.line, f'[B7 Auswahl]: Kurs „{slug}“ unbekannt (vorhanden: {", ".join(sorted(COURSE_TILES))})')
    if len(slugs) > 3:
        ctx.warn(sec.line, f'[B7 Auswahl]: {len(slugs)} Kurse – für Ratgeberseiten höchstens 3')
    lead = ' '.join(b.text for b in blocks if b.kind == 'p')
    head = '<div class="kw-courses-head"><div>' + (f'<p class="kw-eyebrow">{esc(eyebrow)}</p>' if eyebrow else '') \
        + (f'<h2 class="wp-block-heading"{f" id={chr(34)}{sec.anchor}{chr(34)}" if sec.anchor and ctx.page_type == "A" else ""}>{inline(heading)}</h2>' if heading else '') + '</div>' \
        + (f'<p class="kw-lead">{inline(lead)}</p>' if lead else '') + '</div>'
    inner = (html_block(head) + '\n' if (heading or lead or eyebrow) else '') + shortcode_block(f'[kurswasser_course_tiles kurse="{",".join(slugs)}"]')
    inner += '\n' + html_block('<p class="kw-courses-all"><a href="/kurse/">Alle Kurse ansehen<span class="kw-icon kw-icon-arrow" aria-hidden="true"></span></a></p>')
    if ctx.page_type == 'B':
        return inner
    return wide_shell(inner, ctx, cls='kw-section kw-courses is-compact')


def parse_link_item(text: str, ctx: Ctx, line: int) -> tuple[str, str, str, dict]:
    opts: dict = {}
    m = re.search(r'\s*\{([^{}]*)\}\s*$', text)
    if m:
        for part in re.split(r'[,;]\s*|\s+(?=\w+=)', m.group(1).strip()):
            if '=' in part:
                k, v = part.split('=', 1)
                opts[k.strip().lower()] = v.strip()
            elif part.strip():
                opts['icon'] = part.strip()
        text = text[:m.start()]
    for pm in list(re.finditer(r'\s*\((Symbol|Foto)\s+`?([a-z0-9-]+)`?\)', text)):
        opts['icon' if pm.group(1) == 'Symbol' else 'foto'] = pm.group(2)
    text = re.sub(r'\s*\((?:Symbol|Foto)\s+`?[a-z0-9-]+`?\)', '', text)
    lm = re.match(r'^\[([^\]]+)\]\(([^)]+)\)\s*(?:[–—:-]\s*)?(.*)$', text.strip())
    if not lm:
        ctx.err(line, f'Eintrag „{text}“: „[Titel](/ziel/) – Satz“ erwartet')
        return text, '#', '', opts
    return lm.group(1), norm_url(lm.group(2)), lm.group(3), opts


def tile_markup(title: str, url: str, text: str, o: dict, pill_default: str, icon: str, ctx: Ctx, line: int, code: bool, cta: str = 'Lesen') -> str:
    photo = o.get('foto', o.get('photo', ''))
    if photo and photo not in PHOTOS:
        ctx.err(line, f'unbekanntes Foto „{photo}“ (vorhanden: {", ".join(sorted(PHOTOS))})')
        photo = ''
    src = photo_src(photo) if photo else f'%%KWPHOTO:{url}%%'  # sonst Foto der Zielseite (build: resolve_tiles)
    pill = o.get('pill') or (f'%%KWPILL:{url}|{pill_default}%%' if not photo else pill_default)
    code_html = f'<span class="kw-tile-code" aria-hidden="true">{esc(o.get("code", plain(title)))}</span>' if code else ''
    return (f'<article class="kw-tile"><div class="kw-tile-photo" style="background-image:url(\'{src}\')"><span class="kw-tile-pill"><span class="kw-icon kw-icon-{icon}" aria-hidden="true"></span>{esc(pill) if "%%" not in pill else pill}</span>'
            f'{code_html}</div><div class="kw-tile-body"><h3><a href="{esc_attr(url)}">{esc(title)}</a></h3>'
            + (f'<p class="kw-tile-text">{inline(text)}</p>' if text else '')
            + f'</div><footer class="kw-tile-foot"><span class="kw-tile-go">{esc(cta)}<span class="kw-icon kw-icon-arrow" aria-hidden="true"></span></span></footer></article>')


def photo_src(slot: str) -> str:
    return '{{theme}}/assets/images/' + ('hero-boat-960.webp' if slot == 'hero-boat' else f'courses/{slot}-sm.webp')


def r_b8(sec: Section, ctx: Ctx) -> str:
    blocks = list(sec.blocks)
    eyebrow, heading = head_parts(sec, blocks, ctx)
    variant = sec.variant.lower()
    items = [it for b in blocks if b.kind == 'ul' for it in b.items]
    lead = ' '.join(b.text for b in blocks if b.kind == 'p')
    if not items:
        ctx.err(sec.line, '[B8] braucht eine Liste „- [Titel](/ziel/) – Satz {symbol}“')
        return ''
    if variant.startswith('kachel') or variant.startswith('hub'):
        tiles = []
        for it in items:
            title, url, text, o = parse_link_item(it['text'], ctx, it.get('line', sec.line))
            icon = check_icon(o.get('icon', o.get('symbol', '')), ctx, it.get('line', sec.line), 'compass')
            tiles.append(tile_markup(title, url, text, o, eyebrow or 'Ratgeber', icon, ctx, it.get('line', sec.line), code=True))
        cols = 3 if len(tiles) % 3 == 0 or len(tiles) > 4 else 2
        head = f'<div class="kw-overview-head"><h2{f" id={chr(34)}{sec.anchor}{chr(34)}" if sec.anchor else ""}>{inline(heading)}</h2>' + (f'<p class="kw-lead">{inline(lead)}</p>' if lead else '') + '</div>'
        grid = f'<div class="kw-tiles kw-tiles-{cols}">' + ''.join(tiles) + '</div>'
        if ctx.page_type == 'B':
            return html_block(f'<div class="alignwide">{head}{grid}</div>')
        return wide_shell(html_block(head + '\n' + grid), ctx, cls='kw-section kw-overview-section')
    # Weiterlesen: Foto-Kacheln (Foto und Rubrik der Zielseite), Raster passt sich der Kartenzahl an.
    tiles = []
    for n, it in enumerate(items):
        title, url, text, o = parse_link_item(it['text'], ctx, it.get('line', sec.line))
        icon = check_icon(o.get('icon', o.get('symbol', '')), ctx, it.get('line', sec.line), ['anchor', 'compass', 'sail'][n % 3])
        tiles.append(tile_markup(title, url, text, o, 'Ratgeber', icon, ctx, it.get('line', sec.line), code=False))
    if len(items) > 3:
        ctx.warn(sec.line, f'[B8 Weiterlesen]: {len(items)} Einträge (Design: 2–3)')
    eyebrow = eyebrow or 'Weiterlesen'
    if not heading or heading.strip().lower() == eyebrow.lower():
        heading = 'Das gehört auch dazu'
    head = f'<div class="kw-more-head"><p class="kw-eyebrow">{esc(eyebrow)}</p><h2>{inline(heading)}</h2></div>'
    grid = f'<div class="kw-tiles kw-more-tiles">' + ''.join(tiles) + '</div>'
    if ctx.page_type == 'B':
        return html_block(f'<div class="kw-more alignwide">{head}{grid}</div>')
    if ctx.bands.get(id(sec)) == 'mist':
        return band_shell(html_block(f'<div class="kw-more">{head}{grid}</div>'), ctx)
    return wide_shell(html_block(f'<div class="kw-more">{head}{grid}</div>'), ctx)


def r_b9(sec: Section, ctx: Ctx) -> str:
    blocks = list(sec.blocks)
    pattern = take_kv(blocks, 'pattern')
    if pattern:
        eb = take_kv(blocks, 'eyebrow')
        return pattern_markup(pattern[0], ctx, sec.heading, eb[0].text if eb else '')
    eb = take_kv(blocks, 'eyebrow')
    codes = [b for b in blocks if b.kind == 'shortcode']
    if len(codes) != 1 or not codes[0].text.startswith('[bootspruefung_faq'):
        ctx.err(sec.line, '[B9] braucht genau eine Zeile [bootspruefung_faq …] oder „Muster: faq“')
        return ''
    code = codes[0].text
    if ctx.meta.get('faq_count'):
        ctx.warn(sec.line, 'mehr als ein FAQ-Auszug auf der Seite (Design: höchstens einer)')
    ctx.meta['faq_count'] = ctx.meta.get('faq_count', 0) + 1
    is_faq_page = ctx.schema == 'FAQPage'
    attrs = code[len('[bootspruefung_faq'):-1]
    ids = re.search(r'\bids="([^"]*)"', attrs)
    band = ctx.page_type == 'A' and ids is not None
    add = ''
    if sec.heading and ' title=' not in attrs and attrs.strip() and not band:
        add += f' title="{sec.heading.replace(chr(34), "")}"'
    if not is_faq_page and 'schema=' not in attrs:
        add += ' schema="0"'
    if not is_faq_page and 'search=' not in attrs:
        add += ' search="0"'
    if is_faq_page and 'schema="0"' in attrs:
        ctx.warn(sec.line, 'FAQ-Seite (schema FAQPage) mit schema="0" – kein FAQPage-Markup')
    code = code[:-1] + add + ']'
    if ids is not None and len([x for x in re.split(r'[\s,;]+', ids.group(1)) if x]) > 5:
        ctx.warn(sec.line, 'FAQ-Auszug mit mehr als 5 Fragen (Design: höchstens 5, dazu „Alle Fragen ansehen“)')
    if ctx.page_type == 'A' and not is_faq_page and ids is None:
        ctx.warn(sec.line, 'FAQ-Auszug ohne ids="…" – jede Seite zeigt eigene Fragen (höchstens 5)')
    lead = ' '.join(b.text for b in blocks if b.kind == 'p')
    others = [b for b in blocks if b.kind not in ('shortcode', 'p')]
    if others:
        ctx.warn(others[0].line, '[B9]: Text neben dem Shortcode wird ignoriert')
    if band:
        # Nebelband in Lesebreite: Kopf links (Eyebrow, H2, Link zur FAQ-Seite), Fragen rechts, ab Desktop zweispaltig.
        code = re.sub(r'\s+title="[^"]*"', '', code)
        anchor = sec.anchor or ('fragen' if 'fragen' not in ctx.anchors else '')
        ctx.anchors.add(anchor)
        head = p_block(esc(eb[0].text if eb else 'Häufige Fragen'), 'kw-eyebrow') + '\n' + h_block(inline(sec.heading or 'Häufige Fragen'))
        head += ('\n' + p_block(inline(lead), 'kw-lead') if lead else '')
        head += '\n' + p_block(f'<a href="{FAQ_PAGE}">Alle Fragen ansehen</a>', 'kw-faq-all')
        grid = group_block(group_block(head, cls='kw-faq-head') + '\n' + group_block(shortcode_block(code), cls='kw-faq-list'), cls='kw-faq-grid', align='wide')
        out = group_block(grid, tag='section', align='full', anchor=anchor, cls='kw-section kw-mist kw-faq-band', layout={'type': 'constrained', 'wideSize': '1240px'})
        ctx.prev = ('mist', True)
        return out
    ctx.prev = ('white', True)
    if ctx.page_type == 'B' and sec.heading and 'title=' not in code:
        return h_block(inline(sec.heading)) + '\n' + shortcode_block(code)
    return shortcode_block(code)


def r_b10(sec: Section, ctx: Ctx) -> str:
    blocks = list(sec.blocks)
    variant = (sec.variant or '').lower()
    if variant.startswith('garantie') or (sec.heading.lower().startswith('garantie') and not blocks):
        if ctx.meta.get('no_guarantee'):
            ctx.warn(sec.line, 'Garantie-Box auf einer Seite ohne Kaufbezug?')
        ctx.prev = ('white', False)
        return pattern_block('guarantee')
    eyebrow, heading = head_parts(sec, blocks, ctx)
    btns: list[tuple[str, str, str]] = []
    for b in take_kv(blocks, 'button'):
        t = split_target(b.text, ctx, b.line, 'Button')
        if t:
            btns.append((t[0], t[1], 'signal' if not btns else 'ghost-light'))
    links = [b for b in blocks if b.kind == 'ul']
    for b in links:
        for it in b.items:
            lm = re.fullmatch(r'\[([^\]]+)\]\(([^)]+)\)', it['text'].strip())
            if lm:
                btns.append((lm.group(1), norm_url(lm.group(2)), 'signal' if not btns else 'ghost-light'))
        blocks.remove(b)
    if not btns:
        for key, style in (('cta_primary', 'signal'), ('cta_secondary', 'ghost-light')):
            if ctx.meta.get(key):
                btns.append((ctx.meta[key][0], ctx.meta[key][1], style))
    text = ' '.join(inline(b.text) for b in blocks if b.kind == 'p')
    if not heading:
        ctx.err(sec.line, '[B10 CTA] braucht eine Überschrift')
    if ctx.page_type == 'B':
        btns = btns[:2]
        inner = h_block(inline(heading)) + (p_block(text) if text else '') + (buttons_block(btns) if btns else '')
        return group_block(inner, cls='is-style-card-dark', align='wide')
    inner = ''
    if eyebrow:
        inner += '<!-- wp:paragraph {"className":"kw-eyebrow","style":{"color":{"text":"#F5B900"}}} --><p class="kw-eyebrow has-text-color" style="color:#F5B900">' + esc(eyebrow) + '</p><!-- /wp:paragraph -->'
    inner += h_block(inline(heading))
    if text:
        inner += '<!-- wp:paragraph {"style":{"color":{"text":"#C9D8E6"}}} --><p class="has-text-color" style="color:#C9D8E6">' + text + '</p><!-- /wp:paragraph -->'
    if btns:
        inner += buttons_block(btns[:2])
    ctx.prev = ('white', True)
    photo = ctx.meta.get('hero_photo', '')
    cls = 'kw-cta' + (f' kw-photo-{photo}' if photo and photo in PHOTOS and photo != 'hero-boat' else '') + (' is-flip' if ctx.meta.get('hero_flip') else '')
    return group_block(group_block(inner, cls=cls), tag='section', align='wide', cls='kw-section')


RENDERERS = {1: r_b1, 2: r_b2, 3: r_b3, 4: r_b4, 5: r_b5, 6: r_b6, 7: r_b7, 8: r_b8, 9: r_b9, 10: r_b10}


# --------------------------------------------------------------------------- Front-Matter und Datei

def parse_front_matter(lines: list[str], file: str, issues: Issues) -> tuple[dict, dict, int]:
    """Einfaches YAML: „key: wert“ und Listen „  - eintrag“. Gibt Werte, Zeilennummern und Body-Start zurück."""
    if not lines or lines[0].strip() != '---':
        issues.errors.append(f'{file}:1: Front-Matter fehlt (Datei muss mit „---“ beginnen)')
        return {}, {}, 0
    data: dict = {}
    where: dict = {}
    key = None
    for i in range(1, len(lines)):
        raw = lines[i].rstrip('\n')
        if raw.strip() == '---':
            return data, where, i + 1
        if not raw.strip() or raw.lstrip().startswith('#'):
            continue
        m = re.match(r'^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$', raw)
        if m:
            key = m.group(1).lower()
            val = m.group(2).strip()
            if len(val) >= 2 and val[0] == val[-1] and val[0] in '"\'':
                val = val[1:-1]
            if key in data:
                issues.errors.append(f'{file}:{i + 1}: Feld „{key}“ doppelt')
            data[key] = val if val not in ('', '[]') else ([] if val == '[]' else '')
            where[key] = i + 1
            continue
        lm = re.match(r'^\s*-\s+(.*)$', raw)
        if lm and key is not None:
            if data.get(key) in ('', None):
                data[key] = []
            if not isinstance(data[key], list):
                issues.errors.append(f'{file}:{i + 1}: Listeneintrag nach Feld „{key}“ mit Wert')
                continue
            v = lm.group(1).strip()
            if len(v) >= 2 and v[0] == v[-1] and v[0] in '"\'':
                v = v[1:-1]
            data[key].append(v)
            continue
        if raw.startswith((' ', '\t')) and key is not None and isinstance(data.get(key), str):
            data[key] = (data[key] + ' ' + raw.strip()).strip()  # Fortsetzungszeile
            continue
        issues.errors.append(f'{file}:{i + 1}: Zeile im Front-Matter nicht lesbar: „{raw.strip()}“')
    issues.errors.append(f'{file}:1: Front-Matter ohne schließendes „---“')
    return data, where, len(lines)


def brand_len(text: str) -> int:
    return len(text.replace(BRAND_TOKEN, 'x' * BRAND_LEN))


def as_list(v) -> list[str]:
    if isinstance(v, list):
        return [str(x) for x in v if str(x).strip()]
    return [v] if isinstance(v, str) and v.strip() else []


def build_page(path: Path, issues: Issues) -> dict | None:
    file = path.name
    lines = path.read_text(encoding='utf-8').splitlines()
    fm, where, body_start = parse_front_matter(lines, file, issues)
    n_err = len(issues.errors)
    line_of = lambda k: where.get(k, 1)  # noqa: E731

    for k in REQUIRED + (REQUIRED_A if str(fm.get('type', '')).upper() == 'A' else []):
        if fm.get(k) in (None, '', []):
            issues.errors.append(f'{file}:{line_of(k) if k in where else 1}: Pflichtfeld „{k}“ fehlt')
    ptype = str(fm.get('type', '')).upper()
    if ptype and ptype not in ('A', 'B'):
        issues.errors.append(f'{file}:{line_of("type")}: type „{fm.get("type")}“ (erlaubt: A, B)')
    for k, allowed in (('status', {'publish', 'draft'}), ('index', {'index', 'noindex'})):
        if fm.get(k) and fm[k] not in allowed:
            issues.errors.append(f'{file}:{line_of(k)}: {k} „{fm[k]}“ (erlaubt: {", ".join(sorted(allowed))})')
    if fm.get('schema') and fm['schema'] not in SCHEMAS:
        issues.errors.append(f'{file}:{line_of("schema")}: schema „{fm["schema"]}“ unbekannt (erlaubt: {", ".join(sorted(SCHEMAS))})')
    nr = str(fm.get('nr', ''))
    if nr and not nr.isdigit():
        issues.errors.append(f'{file}:{line_of("nr")}: nr muss eine Zahl sein')
    if isinstance(fm.get('seo_title'), str) and fm['seo_title'] and brand_len(fm['seo_title']) > MAX_SEO_TITLE:
        issues.errors.append(f'{file}:{line_of("seo_title")}: seo_title hat {brand_len(fm["seo_title"])} Zeichen (max. {MAX_SEO_TITLE}, {BRAND_TOKEN} = {BRAND_LEN})')
    if isinstance(fm.get('meta_description'), str) and fm['meta_description'] and brand_len(fm['meta_description']) > MAX_META:
        issues.errors.append(f'{file}:{line_of("meta_description")}: meta_description hat {brand_len(fm["meta_description"])} Zeichen (max. {MAX_META}, {BRAND_TOKEN} = {BRAND_LEN})')
    focus = fm.get('focus_keyword', '')
    if isinstance(focus, list):
        issues.errors.append(f'{file}:{line_of("focus_keyword")}: focus_keyword ist ein einzelner Begriff, keine Liste')
        focus = ''
    focus = re.sub(r'\s+', ' ', str(focus or '')).strip()
    if len(focus) > 100:
        issues.errors.append(f'{file}:{line_of("focus_keyword")}: focus_keyword hat {len(focus)} Zeichen (max. 100)')
    if BRAND_TOKEN in focus or '[[' in focus:
        issues.errors.append(f'{file}:{line_of("focus_keyword")}: focus_keyword ohne {BRAND_TOKEN} und Platzhalter')
    title = str(fm.get('title', ''))
    if brand_len(title) > 60:
        issues.warnings.append(f'{file}:{line_of("title")}: H1 hat {brand_len(title)} Zeichen (empfohlen ≤ 60)')
    for word in re.findall(r'\S{23,}', title):
        issues.warnings.append(f'{file}:{line_of("title")}: Wort „{word}“ ist zu lang für 390 px (> 22 Zeichen)')

    slug_path = str(fm.get('slug', '')).strip().strip('/')
    if slug_path and not re.fullmatch(r'[a-z0-9-]+(/[a-z0-9-]+)*', slug_path):
        issues.errors.append(f'{file}:{line_of("slug")}: slug „{slug_path}“ (nur a–z, 0–9, -, / für Unterseiten)')
    parent = str(fm.get('parent', '') or '').strip().strip('/')
    if '/' in slug_path:
        derived = slug_path.rsplit('/', 1)[0]
        if parent and parent != derived and not derived.endswith(parent):
            issues.errors.append(f'{file}:{line_of("parent")}: parent „{parent}“ passt nicht zum Pfad „{slug_path}“')
        parent = derived
    elif parent:
        slug_path = parent + '/' + slug_path

    replaces = str(fm.get('replaces', '') or fm.get('ersetzt', '') or '').strip()
    if replaces:
        m = re.search(r'(impressum|datenschutz|agb|widerruf|kontakt)', replaces.lower())
        replaces = f'legal:{m.group(1)}' if m else ''
        if not replaces:
            issues.errors.append(f'{file}:{line_of("replaces")}: replaces nur für Rechtstexte (z. B. „legal/kontakt“)')
    elif slug_path in LEGAL_SLUGS:
        replaces = f'legal:{slug_path}'

    ctx = Ctx(file=file, page_type=ptype or 'B', schema=str(fm.get('schema', '')), issues=issues, meta={})
    for key in ('cta_primary', 'cta_secondary'):
        if isinstance(fm.get(key), str) and fm[key]:
            t = split_target(fm[key], ctx, line_of(key), key)
            if t:
                ctx.meta[key] = t
    if fm.get('stand'):
        remember_stand(str(fm['stand']), ctx)
        ctx.meta['stand'] = True

    # Inhalt
    content_start = None
    for i in range(body_start, len(lines)):
        if re.match(r'^##\s+Inhalt\s*$', lines[i].strip()):
            content_start = i + 1
            break
    if content_start is None:
        issues.errors.append(f'{file}:{body_start + 1}: Abschnitt „## Inhalt“ fehlt')
        return None
    sections: list[Section] = []
    loose: list[tuple[int, str]] = []
    cur: Section | None = None
    cur_lines: list[tuple[int, str]] = []

    def close() -> None:
        if cur is not None:
            cur.blocks = parse_blocks(cur_lines, ctx)
            sections.append(cur)

    for i in range(content_start, len(lines)):
        raw = lines[i]
        no = i + 1
        if re.match(r'^##\s', raw):
            issues.errors.append(f'{file}:{no}: „##“ im Inhalt – Abschnitte beginnen mit „### [Typ] Überschrift“')
            continue
        hm = re.match(r'^###\s+(.*)$', raw)
        if hm and not raw.startswith('####'):
            close()
            cur_lines = []
            head = hm.group(1).strip()
            tm = re.match(r'^\[([^\]]+)\]\s*(.*)$', head)
            if not tm:
                issues.errors.append(f'{file}:{no}: Abschnitt ohne Typ – „### [Typ] Überschrift“ erwartet')
                cur = None
                continue
            st = section_type(tm.group(1))
            if st is None:
                issues.errors.append(f'{file}:{no}: unbekannter Abschnittstyp „{tm.group(1)}“ (B1–B10 bzw. Fließtext, Hinweis, Schritte, Fakten, Vergleich, Karten, Quellen, Kurse, Weiterlesen, Kacheln, FAQ, CTA, Garantie)')
                cur = None
                continue
            heading, anchor, label = split_anchor_label(tm.group(2).strip())
            cur = Section(st[0], st[1], heading, anchor, no, [], label)
            continue
        if cur is None:
            if raw.strip():
                loose.append((no, raw))
            continue
        cur_lines.append((no, raw))
    close()
    if loose:
        issues.errors.append(f'{file}:{loose[0][0]}: Text vor dem ersten Abschnitt „### [Typ] …“')
    if not sections:
        issues.errors.append(f'{file}:{content_start}: „## Inhalt“ ohne Abschnitte')

    # Seitenkopf-Extras (Front-Matter oder [B1])
    ctx.meta['hero_stats'] = as_list(fm.get('hero_stats'))
    ctx.meta['hero_nav'] = as_list(fm.get('hero_nav'))
    photo_spec = str(fm.get('hero_photo', '') or '').split()
    ctx.meta['hero_photo'] = photo_spec[0] if photo_spec else ''
    ctx.meta['hero_flip'] = 'flip' in photo_spec[1:]
    ctx.meta['toc_mode'] = str(fm.get('toc', '') or 'auto')
    ctx.meta['head_lead'] = str(fm.get('lead', '') or '')
    body_html = render_sections(sections, ctx)
    for item in ctx.meta.get('hero_nav', []) if not ctx.toc_on else []:
        target = item.split('|')[0].strip()
        if target.startswith('#') and target[1:] not in body_html_ids(body_html):
            issues.warnings.append(f'{file}:{line_of("hero_nav")}: hero_nav „{target}“ – kein Abschnitt mit diesem Anker')

    if ptype == 'B':
        body_html = head_shortcode(fm, ctx) + '\n\n' + body_html
    if ptype == 'A':
        bar = toc_bar(ctx, bool(ctx.meta.get('has_rail_top')))
        body_html = hero_shortcode(fm, ctx) + '\n\n' + (bar + '\n\n' if bar else '') + body_html
    if ctx.schema == 'FAQPage' and '[bootspruefung_faq' not in body_html:
        issues.warnings.append(f'{file}:{line_of("schema")}: schema FAQPage ohne [bootspruefung_faq]')
    status = str(fm.get('status', ''))
    if status == 'publish' and (has_placeholder(body_html) or PLACEHOLDER in title):
        issues.warnings.append(f'{file}:{line_of("status")}: enthält [[Betreiber: …]] – wird als Entwurf angelegt')
    if status == 'publish' and ctx.meta.get('has_placeholder_box'):
        issues.warnings.append(f'{file}:{line_of("status")}: enthält einen „Offen“-Kasten (kw-placeholder) – wird als Entwurf angelegt')
    for m in re.finditer(r'href="([^"]*)"', body_html):
        if not url_ok(html.unescape(m.group(1))):
            issues.errors.append(f'{file}:{content_start}: Link „{m.group(1)}“ (erlaubt: /pfad/, #anker, #kw:ziel, https://…, mailto:)')
    if len(issues.errors) > n_err:
        return None

    nr_i = int(nr)
    file_base = slug_path.replace('/', '-')
    return {
        'nr': nr_i,
        'slug': slug_path.rsplit('/', 1)[-1],
        'path': slug_path,
        'parent': parent,
        'title': title,
        'menu_title': str(fm.get('menu_title', '') or title),
        'type': ptype,
        'template': 'course' if ptype == 'A' else 'page-info',
        'status': status,
        'index': str(fm.get('index', 'index')),
        'canonical': str(fm.get('canonical', '') or '').strip(),
        'seo_title': str(fm.get('seo_title', '')),
        'meta_description': str(fm.get('meta_description', '')),
        'focus_keyword': focus,
        'schema': str(fm.get('schema', 'none')),
        'open_items': as_list(fm.get('open_items')),
        'eyebrow': str(fm.get('eyebrow', '') or ''),
        'lead': str(fm.get('lead', '') or ''),
        'cta_primary': list(ctx.meta['cta_primary']) if ctx.meta.get('cta_primary') else [],
        'cta_secondary': list(ctx.meta['cta_secondary']) if ctx.meta.get('cta_secondary') else [],
        'breadcrumb_raw': fm.get('breadcrumb', ''),
        'breadcrumb_line': line_of('breadcrumb'),
        'reviewed': ctx.reviewed,
        'replaces': replaces,
        'file': f'{nr_i:02d}-{file_base}.html',
        'source': file,
        'html': body_html.strip() + '\n',
        '_photo': ctx.meta.get('hero_photo', '') if ptype == 'A' else '',
        '_pill': str(fm.get('eyebrow', '') or '') or str(fm.get('menu_title', '') or ''),
    }


def body_html_ids(html_: str) -> set[str]:
    return set(re.findall(r'\bid="([^"]+)"', html_))


def enc_attr(v: str) -> str:
    return (v.replace('&', '&amp;').replace('"', '&quot;').replace('<', '&lt;').replace('>', '&gt;')
            .replace('[', '&#91;').replace(']', '&#93;'))


def stand_attr(fm: dict, ctx: Ctx) -> str:
    """Sichtbare Zeile „Stand: Monat Jahr“ nur auf Ratgeber- und prüfungsrechtlichen Seiten (Schema Article)."""
    return ctx.reviewed if ctx.reviewed and str(fm.get('schema', '')) == 'Article' else ''


def hero_shortcode(fm: dict, ctx: Ctx) -> str:
    attrs = [('eyebrow', str(fm.get('eyebrow', ''))), ('lead', str(fm.get('lead', '')))]
    for key, name in (('cta_primary', 'primary'), ('cta_secondary', 'secondary')):
        if ctx.meta.get(key):
            attrs.append((name, f'{ctx.meta[key][0]}|{ctx.meta[key][1]}'))
    if str(fm.get('hero_note', '') or '').strip():
        attrs.append(('note', str(fm['hero_note']).strip()))
    photo = ctx.meta.get('hero_photo', '')
    if photo:
        if photo not in PHOTOS:
            ctx.err(1, f'hero_photo „{photo}“ unbekannt (vorhanden: {", ".join(sorted(PHOTOS))})')
        attrs.append(('photo', photo))
        if ctx.meta.get('hero_flip'):
            attrs.append(('flip', '1'))
    stats = []
    for s in ctx.meta.get('hero_stats', []):
        parts = [x.strip() for x in s.split('|')]
        if len(parts) != 3:
            ctx.err(1, f'hero_stats „{s}“: „Symbol | Zahl | Label“ erwartet')
            continue
        check_icon(parts[0], ctx, 1, 'check')
        stats.append('|'.join(parts))
    if stats:
        attrs.append(('stats', '||'.join(stats)))
    navs = []
    for s in ctx.meta.get('hero_nav', []) if not ctx.toc_on else []:  # mit „Auf dieser Seite“ gehen die Chips in der Sprungleiste auf
        parts = [x.strip() for x in s.split('|')]
        if len(parts) != 2 or not parts[0].startswith('#'):
            ctx.err(1, f'hero_nav „{s}“: „#anker | Label“ erwartet')
            continue
        navs.append('|'.join(parts))
    if navs:
        attrs.append(('nav', '||'.join(navs)))
    stand = stand_attr(fm, ctx)
    if stand:
        attrs.append(('stand', stand))
    body = ' '.join(f'{k}="{enc_attr(v)}"' for k, v in attrs if v)
    return shortcode_block(f'[kurswasser_page_hero {body}]')


def head_shortcode(fm: dict, ctx: Ctx) -> str:
    """Typ B: Kopf „Seekarte“ (Brotkrumen, Eyebrow, H1, Einleitung, Sprung-Chips) – Vorlage page-info."""
    attrs = [('eyebrow', str(fm.get('eyebrow', '') or '')), ('lead', str(ctx.meta.get('head_lead', '') or ''))]
    navs = []
    for s in ctx.meta.get('hero_nav', []):
        parts = [x.strip() for x in s.split('|')]
        if len(parts) not in (2, 3) or not re.match(r'^(#|/)', parts[0]):
            ctx.err(1, f'hero_nav „{s}“: „#anker | Label“ bzw. „/pfad/ | Label | symbol“ erwartet')
            continue
        if len(parts) == 3:
            check_icon(parts[2], ctx, 1, 'compass')
        navs.append('|'.join(parts))
    if navs:
        attrs.append(('nav', '||'.join(navs)))
    stand = stand_attr(fm, ctx)
    if stand:
        attrs.append(('stand', stand))
    body = ' '.join(f'{k}="{enc_attr(v)}"' for k, v in attrs if v)
    return shortcode_block(f'[kurswasser_page_head{(" " + body) if body else ""}]')


def resolve_breadcrumbs(pages: list[dict], issues: Issues) -> None:
    by_label: dict[str, str] = {}
    for pg in pages:
        for label in (pg['menu_title'], pg['title']):
            by_label.setdefault(label.strip().lower(), '/' + pg['path'] + '/')
    known = {'kurse': '/kurse/', 'alle kurse': '/kurse/', 'start': '/', 'startseite': '/'}
    for pg in pages:
        raw = pg.pop('breadcrumb_raw')
        line = pg.pop('breadcrumb_line')
        parts = raw if isinstance(raw, list) else [x for x in re.split(r'\s*(?:/|›|»|>)\s*(?![^()]*\))', str(raw)) if x.strip()]
        items: list[list[str]] = []
        label = pg['menu_title']
        if parts:
            if parts[0].strip().lower() in ('start', 'startseite', 'home'):
                parts = parts[1:]
            if parts:
                label = plain(parts[-1])
                parts = parts[:-1]
        elif pg['parent']:
            parent = next((x for x in pages if x['path'] == pg['parent']), None)
            if parent:
                items.append([parent['menu_title'], '/' + parent['path'] + '/'])
        for part in parts:
            part = part.strip()
            m = re.match(r'^\[([^\]]+)\]\(([^)]+)\)$', part) or re.match(r'^(.+?)\s*\((/[^)]*|#kw:[^)]*)\)$', part)
            if m:
                items.append([m.group(1).strip(), m.group(2).strip()])
                continue
            url = known.get(part.lower()) or by_label.get(part.lower())
            if url is None:
                issues.warnings.append(f'{pg["source"]}:{line}: Brotkrumen-Glied „{part}“ ohne Seite – ohne Link (in JSON-LD ausgelassen); „Label (/pfad/)“ angeben')
                url = ''
            items.append([part, url])
        pg['breadcrumb'] = items
        pg['crumb_label'] = label
        if len(label) > 25:
            issues.warnings.append(f'{pg["source"]}:{line}: Brotkrumen-Kurzlabel „{label}“ > 25 Zeichen')


# --------------------------------------------------------------------------- Ausgabe

def php_value(v, indent: int = 2) -> str:
    pad = '    ' * indent
    if isinstance(v, bool):
        return 'true' if v else 'false'
    if isinstance(v, int):
        return str(v)
    if isinstance(v, str):
        return "'" + v.replace('\\', '\\\\').replace("'", "\\'") + "'"
    if isinstance(v, list):
        if not v:
            return '[]'
        if all(not isinstance(x, (list, dict)) for x in v) and len(v) <= 2 and sum(len(str(x)) for x in v) < 80:
            return '[' + ', '.join(php_value(x) for x in v) + ']'
        return '[\n' + ''.join(f'{pad}    {php_value(x, indent + 1)},\n' for x in v) + pad + ']'
    if isinstance(v, dict):
        return '[\n' + ''.join(f"{pad}    {php_value(k)} => {php_value(x, indent + 1)},\n" for k, x in v.items()) + pad + ']'
    raise TypeError(type(v))


MANIFEST_KEYS = ['nr', 'slug', 'path', 'parent', 'title', 'menu_title', 'type', 'template', 'status', 'index', 'canonical',
                 'seo_title', 'meta_description', 'focus_keyword', 'schema', 'open_items', 'eyebrow', 'lead', 'cta_primary', 'cta_secondary',
                 'breadcrumb', 'crumb_label', 'reviewed', 'replaces', 'file', 'source']


def manifest_php(pages: list[dict], src: str) -> str:
    out = ['<?php', '/**',
           ' * Inhaltsseiten – Manifest (eine Zeile je Seite, Eltern vor Kindern).',
           f' * Erzeugt von tools/content_build.py aus {src} – nicht von Hand bearbeiten, sondern neu erzeugen.',
           ' * Block-Markup je Seite: content/pages/<file>. Anlegen: inc/content-pages.php.',
           ' *', ' * @package Kurswasser', ' */', '', 'declare(strict_types=1);', '',
           "defined('ABSPATH') || exit;", '', 'return [']
    for pg in pages:
        out.append('    [')
        for k in MANIFEST_KEYS:
            out.append(f"        {php_value(k)} => {php_value(pg[k], 2)},")
        out.append('    ],')
    out.append('];')
    return '\n'.join(out) + '\n'


def order_pages(pages: list[dict]) -> list[dict]:
    """Nach Nummer, Elternseiten aber immer vor ihren Kindern."""
    by_path = {p['path']: p for p in pages}
    done: list[dict] = []
    seen: set[str] = set()

    def visit(p: dict, stack: tuple = ()) -> None:
        if p['path'] in seen or p['path'] in stack:
            return
        if p['parent'] in by_path:
            visit(by_path[p['parent']], stack + (p['path'],))
        seen.add(p['path'])
        done.append(p)

    for p in sorted(pages, key=lambda x: x['nr']):
        visit(p)
    return done


def build(src: Path, issues: Issues) -> list[dict]:
    files = sorted(f for f in src.glob('*.md') if not f.name.startswith('_') and re.match(r'^\d+-', f.name))
    if not files:
        issues.errors.append(f'{src}: keine Seitendateien „NN-slug.md“ gefunden')
        return []
    pages = [pg for f in files if (pg := build_page(f, issues)) is not None]
    seen_nr: dict[int, str] = {}
    seen_path: dict[str, str] = {}
    for pg in pages:
        if pg['nr'] in seen_nr:
            issues.errors.append(f'{pg["source"]}:1: nr {pg["nr"]} schon in {seen_nr[pg["nr"]]}')
        seen_nr[pg['nr']] = pg['source']
        if pg['path'] in seen_path:
            issues.errors.append(f'{pg["source"]}:1: slug „{pg["path"]}“ schon in {seen_path[pg["path"]]}')
        seen_path[pg['path']] = pg['source']
    paths = {pg['path'] for pg in pages}
    for pg in pages:
        if pg['parent'] and pg['parent'] not in paths:
            issues.warnings.append(f'{pg["source"]}:1: Elternseite „{pg["parent"]}“ nicht im Manifest – muss in WordPress existieren')
        canon = pg['canonical']
        if canon and not url_ok(canon) and canon.strip('/') not in paths:
            issues.warnings.append(f'{pg["source"]}:1: canonical „{canon}“ ist keine Seite des Manifests – muss in WordPress existieren')
    resolve_breadcrumbs(pages, issues)
    resolve_tiles(pages)
    return order_pages(pages)


def resolve_tiles(pages: list[dict]) -> None:
    """Kacheln ohne „(Foto x)“: Foto (hero_photo) und Rubrik (Eyebrow) der Zielseite; Kursseiten /kurse/<kurs>/: Kursfoto."""
    by_url = {'/' + p['path'] + '/': p for p in pages}

    def target(url: str) -> tuple[str, str]:
        path = url.split('#', 1)[0]
        pg = by_url.get(path)
        if pg is not None:
            return (pg['_photo'] or 'hero-boat'), pg['_pill']
        m = re.match(r'^/kurse/([a-z0-9-]+)/$', path)
        if m and m.group(1) in PHOTOS:
            return m.group(1), 'Kurs'
        return 'hero-boat', ''

    for pg in pages:
        h = pg['html']
        h = re.sub(r'%%KWPHOTO:([^%]*)%%', lambda m: photo_src(target(html.unescape(m.group(1)))[0]), h)
        h = re.sub(r'%%KWPILL:([^%|]*)\|([^%]*)%%', lambda m: esc(target(html.unescape(m.group(1)))[1] or m.group(2)), h)
        pg['html'] = h


def write(pages: list[dict], out: Path, src: str) -> None:
    pdir = out / 'pages'
    pdir.mkdir(parents=True, exist_ok=True)
    for old in pdir.glob('[0-9]*-*.html'):
        old.unlink()
    for pg in pages:
        (pdir / pg['file']).write_text(pg['html'], encoding='utf-8')
    (out / 'pages.php').write_text(manifest_php(pages, src), encoding='utf-8')


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description='Markdown der Inhaltsseiten → Block-Markup und Manifest (Theme Kurswasser).')
    ap.add_argument('src', type=Path, help='Ordner mit NN-slug.md')
    ap.add_argument('--out', type=Path, default=Path(__file__).resolve().parent.parent / 'content', help='Zielordner (Standard: themes/kurswasser/content)')
    ap.add_argument('--check', action='store_true', help='nur prüfen, nichts schreiben')
    args = ap.parse_args(argv)
    if not args.src.is_dir():
        print(f'Fehler: {args.src} ist kein Ordner', file=sys.stderr)
        return 2
    issues = Issues()
    pages = build(args.src, issues)
    for w in issues.warnings:
        print(f'Warnung: {w}', file=sys.stderr)
    for e in issues.errors:
        print(f'Fehler: {e}', file=sys.stderr)
    if issues.errors:
        print(f'{len(issues.errors)} Fehler – nichts geschrieben.', file=sys.stderr)
        return 1
    for pg in pages:
        state = pg['status']
        if has_placeholder(pg['html']) or 'kw-placeholder' in pg['html']:
            state += ' → draft'
        print(f'{pg["nr"]:>3}  {pg["type"]}  {state:<16} /{pg["path"]}/  {pg["file"]}')
    if not args.check:
        write(pages, args.out, args.src.name)
        print(f'{len(pages)} Seiten nach {args.out} geschrieben.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
