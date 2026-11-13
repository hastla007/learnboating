#!/usr/bin/env node
/**
 * PDF-Generator (To-do T70): erzeugt druckbare PDFs aus einem gebauten Content-Paket.
 *
 * Aufruf (aus dem Repo-Wurzelordner):
 *   node tools/pdf-build/build-pdfs.mjs [--package=build/<package_id>] [--out=build/pdfs]
 *        [--only=navigation,navigation-solutions,exam,exam-key,vocabulary,samples,texts,spelling]
 *        [--courses=SBF-SEE,SRC] [--html] [--dry-run]
 *
 * Ohne --package nimmt das Skript das neueste Paket unter build/ (Feld built_at im manifest.json).
 * Abhängigkeit: nur Playwright (Chromium). Zuerst require('playwright') (z. B. über NODE_PATH),
 * dann /opt/node-tools/node_modules/playwright.
 *
 * Arten:
 *   navigation            Navigationsaufgaben ohne Lösungen
 *   navigation-solutions  Lösungen der Navigationsaufgaben
 *   exam                  Prüfungs-/Übungsbögen (Fragen mit Antwortmöglichkeiten, ohne Lösung)
 *   exam-key              Lösungsschlüssel zu den Bögen
 *   vocabulary            Funk-Vokabeln Deutsch–Englisch (ohne Kategorie „Buchstabiertafel“)
 *   samples               Muster-Funksprüche (radio_practice, mit Musterlösung)
 *   texts                 Funk-Übungstexte (radio_texts: Übersetzung, Aufnahme, Abgabe)
 *   spelling              Internationale Buchstabiertafel (Buchstaben aus dem Paket, Ziffern aus dem Plugin)
 *
 * Kurszuordnung: Kombi- und Ergänzungskurse zeigen im Lernbereich die PDFs ihrer Teilkurse
 * (SectionFiles::PARTS im Plugin). Ein Kurs bekommt deshalb nur dann ein eigenes PDF, wenn er
 * Inhalte hat, die nicht schon über seine Teilkurse sichtbar sind.
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const require = createRequire(import.meta.url);
const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const PLUGIN = path.join(REPO, 'bootspruefung-learning');

const ALL_KINDS = ['navigation', 'navigation-solutions', 'exam', 'exam-key', 'vocabulary', 'samples', 'texts', 'spelling'];

// ---------------------------------------------------------------------------
// Argumente
// ---------------------------------------------------------------------------
const args = Object.fromEntries(process.argv.slice(2).map((a) => {
    const m = a.match(/^--([^=]+)(?:=(.*))?$/);
    if (!m) {
        fail(`Unbekanntes Argument: ${a}`);
    }
    return [m[1], m[2] ?? true];
}));
if (args.help) {
    console.log(fs.readFileSync(fileURLToPath(import.meta.url), 'utf8').split('*/')[0]);
    process.exit(0);
}
const pkgDir = path.resolve(REPO, typeof args.package === 'string' ? args.package : newestPackage());
const outDir = path.resolve(REPO, typeof args.out === 'string' ? args.out : 'build/pdfs');
const only = typeof args.only === 'string' ? args.only.split(',').map((s) => s.trim()) : ALL_KINDS;
const onlyCourses = typeof args.courses === 'string' ? new Set(args.courses.split(',').map((s) => s.trim())) : null;
for (const k of only) {
    if (!ALL_KINDS.includes(k)) {
        fail(`Unbekannte Art „${k}“. Erlaubt: ${ALL_KINDS.join(', ')}`);
    }
}

function fail(msg) {
    console.error(`Fehler: ${msg}`);
    process.exit(1);
}

function newestPackage() {
    const buildDir = path.join(REPO, 'build');
    const found = fs.existsSync(buildDir) ? fs.readdirSync(buildDir)
        .map((d) => path.join(buildDir, d))
        .filter((d) => fs.existsSync(path.join(d, 'manifest.json')) && fs.existsSync(path.join(d, 'data')))
        .map((d) => ({ d, built: JSON.parse(fs.readFileSync(path.join(d, 'manifest.json'), 'utf8')).built_at || '' }))
        .sort((a, b) => b.built.localeCompare(a.built)) : [];
    if (!found.length) {
        fail('Kein Content-Paket unter build/ gefunden. Bitte --package=<ordner> angeben.');
    }
    return found[0].d;
}

// ---------------------------------------------------------------------------
// Paket laden
// ---------------------------------------------------------------------------
const readJson = (rel) => JSON.parse(fs.readFileSync(path.join(pkgDir, rel), 'utf8'));
const manifest = readJson('manifest.json');
const data = {};
for (const name of ['courses', 'navigation_tasks', 'exam_papers', 'questions', 'radio_vocabulary', 'radio_texts', 'radio_practice', 'media']) {
    const file = path.join(pkgDir, 'data', `${name}.json`);
    data[name] = fs.existsSync(file) ? readJson(`data/${name}.json`) : [];
}
const courses = [...data.courses].sort((a, b) => a.sort_order - b.sort_order);
const courseName = Object.fromEntries(courses.map((c) => [c.code, c.name]));
const questionById = new Map(data.questions.map((q) => [q.source_id, q]));
const mediaById = new Map(data.media.map((m) => [m.source_id, m]));

const stand = (() => {
    const d = manifest.source?.commit_date || manifest.built_at;
    const date = d ? new Date(d) : null;
    const de = date && !Number.isNaN(date.getTime())
        ? `${String(date.getUTCDate()).padStart(2, '0')}.${String(date.getUTCMonth() + 1).padStart(2, '0')}.${date.getUTCFullYear()}`
        : '';
    return `${de ? `${de}, ` : ''}Paket ${manifest.package_id}`;
})();

// ---------------------------------------------------------------------------
// Werte aus dem Plugin (nicht doppelt pflegen)
// ---------------------------------------------------------------------------
function readPlugin(rel) {
    const file = path.join(PLUGIN, rel);
    if (!fs.existsSync(file)) {
        fail(`Plugin-Datei fehlt: ${file}`);
    }
    return fs.readFileSync(file, 'utf8');
}

/** SectionFiles::PARTS – Kombikurse/Ergänzungen zeigen die Dateien dieser Kurse mit an. */
const PARTS = (() => {
    const src = readPlugin('src/Gate/SectionFiles.php');
    const block = src.match(/const PARTS = \[([\s\S]*?)\];/);
    if (!block) {
        fail('SectionFiles::PARTS nicht gefunden.');
    }
    const out = {};
    for (const m of block[1].matchAll(/'([^']+)'\s*=>\s*\[([^\]]*)\]/g)) {
        out[m[1]] = [...m[2].matchAll(/'([^']+)'/g)].map((x) => x[1]);
    }
    if (!Object.keys(out).length) {
        fail('SectionFiles::PARTS ist leer oder nicht lesbar.');
    }
    return out;
})();

/** Texte des Lernbereichs (Frontend/Strings.php), z. B. Bezeichnungen der Funk-Aufgabenarten. */
const STRINGS = (() => {
    const src = readPlugin('src/Frontend/Strings.php');
    const out = {};
    for (const m of src.matchAll(/'([a-z0-9_]+)'\s*=>\s*__\('((?:[^'\\]|\\.)*)'/g)) {
        out[m[1]] = m[2].replace(/\\'/g, "'");
    }
    return out;
})();
const str = (key, fallback, vars = {}) => Object.entries(vars)
    .reduce((s, [k, v]) => s.replaceAll(`{${k}}`, v), STRINGS[key] ?? fallback);

/** Ziffern der Buchstabiertafel (Trainers/RadioText.php): UBI deutsch, SRC englisch mit Sprechform. */
const DIGITS = (() => {
    const src = readPlugin('src/Trainers/RadioText.php');
    const de = src.match(/const DIGITS = \[([^\]]*)\];/);
    const en = src.match(/const DIGITS_EN = \[([\s\S]*?)\];\n/);
    if (!de || !en) {
        console.warn('Warnung: Ziffern in RadioText.php nicht gefunden – Buchstabiertafel ohne Ziffern.');
        return null;
    }
    const words = (s) => [...s.matchAll(/'([^']+)'/g)].map((x) => x[1]);
    const enList = [...en[1].matchAll(/\[([^\]]*)\]/g)].map((x) => words(x[1]));
    const deList = words(de[1]);
    if (deList.length !== 10 || enList.length !== 10) {
        console.warn('Warnung: Ziffern in RadioText.php unvollständig – Buchstabiertafel ohne Ziffern.');
        return null;
    }
    return { UBI: deList, SRC: enList };
})();

// ---------------------------------------------------------------------------
// Hilfen
// ---------------------------------------------------------------------------
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const text = (s) => esc(s).replace(/\r?\n/g, '<br>');
const letter = (i) => String.fromCharCode(97 + i); // a, b, c, …
const uniq = (list) => [...new Set(list.filter(Boolean))];
const slug = (code) => code.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/** Fester Zufall je Bogen und Frage, damit Bogen und Lösungsschlüssel immer zusammenpassen. */
function seededShuffle(list, seed) {
    let h = crypto.createHash('sha256').update(seed).digest();
    let i = 0;
    const next = () => {
        if (i + 4 > h.length) {
            h = crypto.createHash('sha256').update(h).digest();
            i = 0;
        }
        const v = h.readUInt32BE(i);
        i += 4;
        return v / 0x100000000;
    };
    const out = [...list];
    for (let j = out.length - 1; j > 0; j--) {
        const k = Math.floor(next() * (j + 1));
        [out[j], out[k]] = [out[k], out[j]];
    }
    return out;
}

/**
 * Bilder werden über eine interne Adresse direkt aus dem Paketordner geladen (siehe main:
 * context.route); das HTML bleibt dadurch klein. Hinweis: Chromium bettet ein Bild je Vorkommen
 * ein, auch wenn es mehrfach vorkommt. null, wenn die Datei nicht im Paket liegt.
 */
const MEDIA_BASE = 'http://paket-medien.invalid/';
function imageData(media) {
    if (!media?.path) {
        return null;
    }
    const file = path.join(pkgDir, media.path);
    return fs.existsSync(file) ? MEDIA_BASE + media.path.split('/').map(encodeURIComponent).join('/') : null;
}

const stats = { imagesEmbedded: 0, imagesMissing: 0 };
function figures(mediaRefs) {
    return (mediaRefs || []).slice().sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0)).map((ref) => {
        const media = mediaById.get(ref.media_source_id);
        const src = imageData(media);
        if (!src) {
            stats.imagesMissing++;
            return '<p class="img-missing">Abbildung im Lernbereich</p>';
        }
        stats.imagesEmbedded++;
        const alt = media.description || media.alt_text || 'Abbildung zur Frage';
        return `<figure><img src="${src}" alt="${esc(alt)}"></figure>`;
    }).join('');
}

/** Quelle einer Abbildung ohne Adresse/Seitenangabe, z. B. „ELWIS (Wasserstraßen- und Schifffahrtsverwaltung des Bundes)“. */
const mediaSourceName = (m) => (m?.source || '').split(/:\s/)[0].trim();

/**
 * Inhalte je Kurs ermitteln. Kombi-/Ergänzungskurse (PARTS) bekommen nur die Inhalte, die
 * nicht schon über ihre Teilkurse im Lernbereich erscheinen.
 * @param {Map<string, any[]>} byCourse
 * @param {(item:any)=>string} keyOf
 */
function perCourse(byCourse, keyOf) {
    const out = [];
    for (const c of courses) {
        const items = byCourse.get(c.code) || [];
        if (!items.length || (onlyCourses && !onlyCourses.has(c.code))) {
            continue;
        }
        const covered = new Set((PARTS[c.code] || []).flatMap((p) => (byCourse.get(p) || []).map(keyOf)));
        const rest = items.filter((i) => !covered.has(keyOf(i)));
        if (rest.length) {
            out.push({ code: c.code, items: rest, partial: rest.length < items.length });
        }
    }
    return out;
}

function groupByCourseCodes(items) {
    const map = new Map();
    for (const item of items) {
        for (const code of item.course_codes || []) {
            if (!map.has(code)) {
                map.set(code, []);
            }
            map.get(code).push(item);
        }
    }
    for (const list of map.values()) {
        list.sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
    }
    return map;
}

// ---------------------------------------------------------------------------
// HTML-Gerüst
// ---------------------------------------------------------------------------
const FONT = "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif"; // einfache Anführungszeichen: steht auch in style="…"
const CSS = `
html { font-family: ${FONT}; font-size: 10.5pt; line-height: 1.4; color: #000; background: #fff; }
body { margin: 0; }
h1 { font-size: 18pt; line-height: 1.2; margin: 0 0 3mm; }
h2 { font-size: 13.5pt; line-height: 1.25; margin: 7mm 0 2.5mm; break-after: avoid; }
h3 { font-size: 11pt; margin: 4mm 0 1.5mm; break-after: avoid; }
p { margin: 0 0 2mm; }
.intro { border: 1pt solid #000; padding: 3mm 4mm; margin: 0 0 5mm; }
.intro dl { display: grid; grid-template-columns: max-content 1fr; gap: 1mm 4mm; margin: 0; }
.intro dt { font-weight: 700; }
.intro dd { margin: 0; }
.note { border-left: 2.5pt solid #000; padding: 1mm 0 1mm 3mm; margin: 3mm 0; }
.new-page { break-before: page; }
.new-page:first-of-type { break-before: auto; }
table { border-collapse: collapse; width: 100%; margin: 0 0 4mm; }
caption { text-align: left; font-weight: 700; padding: 0 0 1.5mm; }
th, td { border: 0.6pt solid #000; padding: 1.4mm 2mm; text-align: left; vertical-align: top; }
th { background: #e8e8e8; font-weight: 700; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
td.num, th.num { width: 12mm; text-align: center; }
ol.questions { margin: 0; padding-left: 8mm; }
ol.questions > li { margin: 0 0 4mm; break-inside: avoid; padding-left: 1mm; }
ol.questions > li::marker { font-weight: 700; }
.qtext { font-weight: 700; margin: 0 0 1.5mm; }
ul.answers { list-style: none; margin: 0; padding: 0; }
ul.answers li { margin: 0 0 1mm; display: flex; gap: 2mm; }
.box { flex: none; width: 3.4mm; height: 3.4mm; border: 0.9pt solid #000; margin-top: 0.6mm; }
.key { flex: none; font-weight: 700; min-width: 4mm; }
.hint { font-style: italic; margin: 0 0 1.5mm; }
.write { border-bottom: 0.6pt solid #000; height: 8mm; }
figure { margin: 1.5mm 0 2mm; }
img { max-width: 100%; max-height: 70mm; height: auto; }
.img-missing { border: 0.8pt dashed #000; padding: 2mm 3mm; display: inline-block; margin: 1mm 0 2mm; }
.radio { white-space: pre-line; font-family: ${FONT}; border: 0.8pt solid #000; padding: 2mm 3mm; margin: 1mm 0 3mm; letter-spacing: 0.02em; }
.cols { display: grid; grid-template-columns: 1fr 1fr; column-gap: 8mm; }
.cols section { break-inside: auto; }
.cols h2 { margin-top: 2mm; font-size: 12pt; }
.cols table { font-size: 9.5pt; line-height: 1.2; }
.cols th, .cols td { padding: 0.5mm 2mm; }
.task { break-inside: avoid-page; }
`;

function page({ title, course, intro, body }) {
    return `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>${esc(title)} – ${esc(courseName[course] || course)}</title>
<style>${CSS}</style>
</head>
<body>
<main>
<h1>${esc(title)}</h1>
<div class="intro">
<dl>
<dt>Kurs</dt><dd>${esc(courseName[course] || course)}</dd>
${intro.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${v}</dd>`).join('\n')}
</dl>
</div>
${body}
</main>
</body>
</html>`;
}

function headerFooter({ title, course, source }) {
    const base = `font-family:${FONT};font-size:7.5pt;color:#000;width:100%;margin:0 16mm;display:flex;justify-content:space-between;gap:6mm;`;
    return {
        headerTemplate: `<div style="${base}border-bottom:0.5pt solid #000;padding-bottom:1mm;"><span>Kurs: ${esc(courseName[course] || course)}</span><span style="text-align:right">${esc(title)}</span></div>`,
        footerTemplate: `<div style="${base}border-top:0.5pt solid #000;padding-top:1mm;"><span>Stand: ${esc(stand)}<br>Quelle: ${esc(source)}</span><span style="white-space:nowrap">Seite <span class="pageNumber"></span> von <span class="totalPages"></span></span></div>`,
    };
}

// ---------------------------------------------------------------------------
// Dokumente
// ---------------------------------------------------------------------------
const docs = [];
/**
 * @param {object} d kind, course, file, title, caption, feature, slot, source, intro, body
 */
function addDoc(d) {
    docs.push({ ...d, html: page(d) });
}

const SLOT_LABELS = {
    '': 'Hauptseite des Bereichs',
    vocabulary: 'Funk: auch auf der Vokabelseite',
    samples: 'Funk: auch auf der Seite Muster-Funksprüche',
    spelling: 'Funk: auch auf der Seite Buchstabiertafel',
};
const FEATURE_LABELS = {
    navigation_trainer: 'Navigationstrainer',
    exam_simulation: 'Prüfungssimulation',
    radio_training: 'Funktraining',
};

// (1) + (2) Navigationsaufgaben ------------------------------------------------
if (only.includes('navigation') || only.includes('navigation-solutions')) {
    const tasks = data.navigation_tasks.filter((t) => t.status === 'published');
    for (const { code, items } of perCourse(groupByCourseCodes(tasks), (t) => t.source_id)) {
        const versions = uniq(items.map((t) => t.source_version));
        const hosts = uniq(items.map((t) => { try { return new URL(t.source).host.replace(/^www\./, ''); } catch { return null; } }));
        const source = `${hosts.length ? hosts.join(', ') : 'Content-Paket'}, Navigationsaufgaben${versions.length ? `, Stand ${versions.join(', ')}` : ''}`;
        const chartNote = '<p class="note">Für diese Aufgaben braucht ihr die Seekarte (Übungskarte D49). Sie ist nicht Teil dieser Datei.</p>';
        const base = `${slug(code)}-navigationsaufgaben`;

        if (only.includes('navigation')) {
            const title = 'Navigationsaufgaben';
            const body = items.map((t, i) => `
<section class="${i ? 'new-page' : ''}">
<h2>Navigationsaufgabe ${t.task_number}</h2>
<p>${text(t.scenario_text)}</p>
<ol class="questions">
${t.questions.slice().sort((a, b) => a.sort_order - b.sort_order).map((q) => `<li value="${q.question_number}"><p class="qtext">${text(q.question_text)}</p><div class="write" aria-hidden="true"></div><div class="write" aria-hidden="true"></div></li>`).join('\n')}
</ol>
</section>`).join('\n');
            addDoc({
                kind: 'navigation', course: code, file: `${slug(code)}/${base}.pdf`, title,
                caption: `${items.length} Aufgaben zum Ausdrucken, ohne Lösungen`,
                feature: 'navigation_trainer', slot: '', source,
                intro: [['Inhalt', `${items.length} Navigationsaufgaben mit ${items.reduce((s, t) => s + t.questions.length, 0)} Fragen, ohne Lösungen`],
                    ['Lösungen', 'in der Datei „Lösungen der Navigationsaufgaben“'], ['Quelle', esc(source)]],
                body: chartNote + body,
            });
        }
        if (only.includes('navigation-solutions')) {
            const title = 'Lösungen der Navigationsaufgaben';
            const body = items.map((t) => `
<section class="task">
<h2>Navigationsaufgabe ${t.task_number}</h2>
<table>
<thead><tr><th class="num" scope="col">Nr.</th><th scope="col">Frage</th><th scope="col">Lösung</th></tr></thead>
<tbody>
${t.questions.slice().sort((a, b) => a.sort_order - b.sort_order).map((q) => `<tr><td class="num">${q.question_number}</td><td>${text(q.question_text)}</td><td>${text(q.answer_text)}</td></tr>`).join('\n')}
</tbody>
</table>
${t.hint ? `<p class="note"><strong>Hinweis:</strong> ${text(t.hint)}</p>` : ''}
</section>`).join('\n');
            addDoc({
                kind: 'navigation-solutions', course: code, file: `${slug(code)}/${base}-loesungen.pdf`, title,
                caption: `Lösungen zu allen ${items.length} Navigationsaufgaben`,
                feature: 'navigation_trainer', slot: '', source,
                intro: [['Inhalt', `Lösungen zu ${items.length} Navigationsaufgaben`], ['Quelle', esc(source)]],
                body: chartNote + body,
            });
        }
    }
}

// (3) Prüfungsbögen und Lösungsschlüssel -------------------------------------
if (only.includes('exam') || only.includes('exam-key')) {
    const byCourse = new Map();
    for (const p of data.exam_papers) {
        if (!byCourse.has(p.course_code)) {
            byCourse.set(p.course_code, []);
        }
        byCourse.get(p.course_code).push(p);
    }
    for (const list of byCourse.values()) {
        list.sort((a, b) => a.sort_order - b.sort_order || a.paper_number - b.paper_number);
    }
    const origin = (p) => p.copied_from || p.source_id;

    for (const { code, items: papers, partial } of perCourse(byCourse, origin)) {
        const practice = papers.every((p) => /uebungsboegen/.test(p.source_file || ''));
        const plural = practice ? 'Übungsbögen' : 'Prüfungsbögen';
        const singular = practice ? 'Übungsbogen' : 'Prüfungsbogen';
        const nums = papers.map((p) => p.paper_number);
        const range = nums.length > 1 ? `${Math.min(...nums)}–${Math.max(...nums)}` : String(nums[0]);

        // Fragen auflösen, Antworten fest mischen (im Paket steht die richtige Antwort meist an erster Stelle).
        const resolved = papers.map((p) => ({
            paper: p,
            questions: p.questions.slice().sort((a, b) => a.position - b.position).map((ref) => {
                const q = questionById.get(ref.question_source_id);
                if (!q) {
                    console.warn(`Warnung: Frage ${ref.question_source_id} (${p.source_id}) fehlt im Paket.`);
                    return null;
                }
                const rev = q.revision;
                const answers = rev.answers.slice().sort((a, b) => a.sort_order - b.sort_order);
                const shown = rev.question_type === 'open_text' ? [] : seededShuffle(answers, `${origin(p)}|${q.source_id}`);
                return { position: ref.position, q, rev, shown, model: rev.question_type === 'open_text' ? answers.filter((a) => a.is_correct) : [] };
            }).filter(Boolean),
        }));

        const catalogs = new Map();
        const imgSources = new Set();
        for (const r of resolved) {
            for (const x of r.questions) {
                const s = x.rev.source || {};
                if (s.catalog) {
                    if (!catalogs.has(s.catalog)) {
                        catalogs.set(s.catalog, new Set());
                    }
                    if (s.version) {
                        catalogs.get(s.catalog).add(s.version);
                    }
                }
                for (const m of x.rev.media || []) {
                    const name = mediaSourceName(mediaById.get(m.media_source_id));
                    if (name) {
                        imgSources.add(name);
                    }
                }
            }
        }
        const catalogText = [...catalogs].map(([c, v]) => (v.size ? `${c} (Version ${[...v].join(', ')})` : c)).join('; ');
        const source = `${catalogText || 'Content-Paket'}${imgSources.size ? `; Abbildungen: ${[...imgSources].join('; ')}` : ''}`;
        const qCount = resolved.reduce((s, r) => s + r.questions.length, 0);
        const base = `${slug(code)}-${practice ? 'uebungsboegen' : 'pruefungsboegen'}`;
        const partialNote = partial
            ? `<p class="note">Die übrigen Bögen dieses Kurses stehen in den Dateien der Teilkurse (${(PARTS[code] || []).map((c) => esc(courseName[c] || c)).join(', ')}).</p>`
            : '';

        if (only.includes('exam')) {
            const title = `${plural} ${range}`;
            const body = resolved.map((r, i) => `
<section class="${i ? 'new-page' : ''}">
<h2>${singular} ${r.paper.paper_number}</h2>
<ol class="questions">
${r.questions.map((x) => {
    const multi = x.rev.question_type === 'multi_choice';
    const open = x.rev.question_type === 'open_text';
    const answers = open
        ? '<div class="write" aria-hidden="true"></div><div class="write" aria-hidden="true"></div><div class="write" aria-hidden="true"></div>'
        : `<ul class="answers">${x.shown.map((a, k) => `<li><span class="box" aria-hidden="true"></span><span class="key">${letter(k)})</span><span>${text(a.answer_text)}</span></li>`).join('')}</ul>`;
    return `<li value="${x.position}"><p class="qtext">${text(x.rev.question_text)}</p>${multi ? '<p class="hint">Mehrere Antworten können richtig sein.</p>' : ''}${figures(x.rev.media)}${answers}</li>`;
}).join('\n')}
</ol>
</section>`).join('\n');
            addDoc({
                kind: 'exam', course: code, file: `${slug(code)}/${base}.pdf`, title,
                caption: `${papers.length} Bögen zum Ausdrucken, ohne Lösungen`,
                feature: 'exam_simulation', slot: '', source,
                intro: [['Inhalt', `${papers.length} ${plural} (Nr. ${range}) mit zusammen ${qCount} Fragen, ohne Lösungen`],
                    ['Ankreuzen', 'Die Reihenfolge der Antworten ist gemischt. Die Lösungen stehen im passenden Lösungsschlüssel.'],
                    ['Quelle', esc(source)]],
                body: partialNote + body,
            });
        }
        if (only.includes('exam-key')) {
            const title = `Lösungsschlüssel ${plural} ${range}`;
            const hasOpen = resolved.some((r) => r.questions.some((x) => x.rev.question_type === 'open_text'));
            const sections = resolved.map((r) => `
<section>
<h2>${singular} ${r.paper.paper_number}</h2>
<table>
<thead><tr><th class="num" scope="col">Frage</th><th scope="col">Lösung</th><th scope="col">Kennung</th></tr></thead>
<tbody>
${r.questions.map((x) => {
    const sol = x.rev.question_type === 'open_text'
        ? x.model.map((a) => text(a.answer_text)).join('<br>')
        : x.shown.map((a, k) => (a.is_correct ? letter(k) : null)).filter(Boolean).join(', ');
    return `<tr><td class="num">${x.position}</td><td>${sol || '–'}</td><td>${esc(x.q.source_id)}</td></tr>`;
}).join('\n')}
</tbody>
</table>
</section>`).join('\n');
            addDoc({
                kind: 'exam-key', course: code, file: `${slug(code)}/${base}-loesungsschluessel.pdf`, title,
                caption: `Richtige Antworten zu den ${plural} ${range}`,
                feature: 'exam_simulation', slot: '', source,
                intro: [['Inhalt', `Richtige Antworten zu ${papers.length} ${plural} (Nr. ${range})`],
                    ['Hinweis', `Gilt nur für die ${plural} aus derselben Ausgabe (Stand unten auf jeder Seite), weil die Antworten dort gemischt sind.`],
                    ['Quelle', esc(source)]],
                body: partialNote + (hasOpen ? sections : `<div class="cols">${sections}</div>`),
            });
        }
    }
}

// (4)–(6) Funk ----------------------------------------------------------------
const catalogOfCourse = (items) => uniq(items.map((i) => i.catalog)).join(', ');

if (only.includes('vocabulary')) {
    const vocab = data.radio_vocabulary.filter((v) => v.category !== 'Buchstabiertafel');
    for (const { code, items } of perCourse(groupByCourseCodes(vocab), (v) => v.source_id)) {
        const cats = uniq(items.map((v) => v.category));
        const catalog = catalogOfCourse(items);
        const hasHint = items.some((v) => v.hint);
        const source = `Content-Paket, Funk-Vokabeln Katalog ${catalog}`;
        const body = cats.map((cat) => {
            const rows = items.filter((v) => v.category === cat);
            return `<section><h2>${esc(cat)}</h2>
<table>
<thead><tr><th scope="col">Deutsch</th><th scope="col">Englisch</th>${hasHint ? '<th scope="col">Hinweis</th>' : ''}</tr></thead>
<tbody>
${rows.map((v) => `<tr><td>${text(v.term_de)}</td><td lang="en">${text(v.term_en)}</td>${hasHint ? `<td>${text(v.hint)}</td>` : ''}</tr>`).join('\n')}
</tbody>
</table></section>`;
        }).join('\n');
        addDoc({
            kind: 'vocabulary', course: code, file: `${slug(code)}/${slug(code)}-funk-vokabeln.pdf`, title: 'Funk-Vokabeln Deutsch–Englisch',
            caption: `${items.length} Vokabeln in ${cats.length} Themen`,
            feature: 'radio_training', slot: 'vocabulary', source,
            intro: [['Inhalt', `${items.length} Vokabeln (Katalog ${esc(catalog)}) in ${cats.length} Themen: ${cats.map(esc).join(', ')}`],
                ['Buchstabiertafel', 'in einer eigenen Datei']],
            body,
        });
    }
}

if (only.includes('samples')) {
    const kindLabel = (k) => str(`samples_kind_${k}`, k);
    for (const { code, items } of perCourse(groupByCourseCodes(data.radio_practice), (p) => p.source_id)) {
        const catalog = catalogOfCourse(items);
        const source = uniq(items.map((p) => p.source)).join('; ') || 'Content-Paket';
        const body = items.map((p) => {
            const fields = p.fields || [];
            const short = fields.filter((f) => !f.multiline);
            const long = fields.filter((f) => f.multiline);
            return `<section class="task">
<h2>Aufgabe ${esc(p.catalog)} ${p.number}: ${esc(kindLabel(p.kind))}</h2>
<p><strong>Lage:</strong> ${text(p.situation_de)}</p>
${short.length ? `<table>
<thead><tr><th scope="col" style="width:38mm">Feld</th><th scope="col">Musterlösung</th></tr></thead>
<tbody>
${short.map((f) => `<tr><th scope="row">${esc(f.label)}</th><td>${text(f.solution)}</td></tr>`).join('\n')}
</tbody>
</table>` : ''}
${long.map((f) => `<h3>${esc(f.label)}</h3><div class="radio">${esc(f.solution)}</div>`).join('\n')}
</section>`;
        }).join('\n');
        addDoc({
            kind: 'samples', course: code, file: `${slug(code)}/${slug(code)}-muster-funksprueche.pdf`, title: 'Muster-Funksprüche',
            caption: `${items.length} Übungslagen mit Musterlösung (${catalog})`,
            feature: 'radio_training', slot: 'samples', source,
            intro: [['Inhalt', `${items.length} Übungslagen (Katalog ${esc(catalog)}) mit Musterlösung`], ['Quelle', esc(source)]],
            body,
        });
    }
}

if (only.includes('texts')) {
    const letters = new Map(data.radio_vocabulary.filter((v) => v.category === 'Buchstabiertafel').map((v) => [String(v.term_de).toUpperCase(), v.term_en]));
    // Wie RadioText::spellGerman im Plugin: Umlaute auflösen, Ziffern deutsch, Buchstaben laut Tafel.
    const spellGerman = (word) => [...String(word).toUpperCase().replace(/Ä/g, 'AE').replace(/Ö/g, 'OE').replace(/Ü/g, 'UE').replace(/ß/g, 'SS')]
        .map((c) => (/\d/.test(c) ? DIGITS?.UBI?.[Number(c)] : letters.get(c))).filter(Boolean).join(' ');
    for (const { code, items } of perCourse(groupByCourseCodes(data.radio_texts), (t) => t.source_id)) {
        const catalog = catalogOfCourse(items);
        const source = uniq(items.map((t) => (t.source || '').replace(/\s*\(siehe Dateikopf\)\s*$/, ''))).join('; ') || 'Content-Paket';
        const groups = [];
        for (const t of items) {
            const key = `${t.catalog}|${t.exercise_type}|${t.direction ?? ''}`;
            let g = groups.find((x) => x.key === key);
            if (!g) {
                g = { key, t, list: [] };
                groups.push(g);
            }
            g.list.push(t);
        }
        const body = groups.map(({ t, list }) => {
            const heading = str(`radio_type_${t.exercise_type}`, t.exercise_type, { catalog: t.catalog })
                + (t.direction ? ` (${str(`radio_direction_${t.direction}`, t.direction)})` : '');
            const explain = str(`radio_type_${t.exercise_type}_explain`, '');
            let inner;
            if (t.exercise_type === 'translation') {
                inner = `<table>
<thead><tr><th class="num" scope="col">Nr.</th><th scope="col">Deutsch</th><th scope="col">Englisch</th></tr></thead>
<tbody>
${list.map((x) => `<tr><td class="num">${x.number}</td><td>${text(x.text_de)}</td><td lang="en">${text(x.text_en)}</td></tr>`).join('\n')}
</tbody>
</table>`;
            } else if (t.exercise_type === 'spelling') {
                inner = list.map((x) => {
                    const lines = x.lines || [];
                    const marked = lines.flatMap((l) => [...l.matchAll(/\*([^*]+)\*/g)].map((m) => m[1]));
                    return `<section class="task"><h3>Text ${x.number}</h3>
<div class="radio">${lines.map((l) => esc(l).replace(/\*([^*]+)\*/g, '<strong>$1</strong>')).join('\n')}</div>
${marked.length ? `<table>
<thead><tr><th scope="col" style="width:38mm">Markiertes Wort</th><th scope="col">Buchstabiert</th></tr></thead>
<tbody>
${marked.map((w) => `<tr><td>${esc(w)}</td><td>${esc(spellGerman(w))}</td></tr>`).join('\n')}
</tbody>
</table>` : ''}</section>`;
                }).join('\n');
            } else {
                inner = list.map((x) => `<section class="task"><h3>Text ${x.number}</h3><div class="radio">${esc(x.text_de || (x.lines || []).join('\n'))}</div></section>`).join('\n');
            }
            return `<section><h2>${esc(heading)}</h2>${explain ? `<p>${esc(explain)}</p>` : ''}${inner}</section>`;
        }).join('\n');
        addDoc({
            kind: 'texts', course: code, file: `${slug(code)}/${slug(code)}-funk-uebungstexte.pdf`, title: 'Funk-Übungstexte',
            caption: `${items.length} Übungstexte (${catalog}) zum Nachlesen`,
            feature: 'radio_training', slot: '', source,
            intro: [['Inhalt', `${items.length} Übungstexte (Katalog ${esc(catalog)}): ${groups.map((g) => `${esc(str(`radio_type_${g.t.exercise_type}`, g.t.exercise_type, { catalog: g.t.catalog }))}${g.t.direction ? ` ${esc(str(`radio_direction_${g.t.direction}`, g.t.direction))}` : ''} (${g.list.length})`).join(', ')}`],
                ['Quelle', esc(source)]],
            body,
        });
    }
}

if (only.includes('spelling')) {
    const table = data.radio_vocabulary.filter((v) => v.category === 'Buchstabiertafel');
    for (const { code, items } of perCourse(groupByCourseCodes(table), (v) => v.source_id)) {
        const catalogs = uniq(items.map((v) => v.catalog));
        const hasHint = items.some((v) => v.hint);
        const lettersHtml = `<section><h2>${esc(str('spelling_letters', 'Buchstaben'))}</h2>
<table>
<thead><tr><th class="num" scope="col">Buchstabe</th><th scope="col">Wort</th>${hasHint ? '<th scope="col">Aussprache</th>' : ''}</tr></thead>
<tbody>
${items.map((v) => `<tr><td class="num"><strong>${esc(v.term_de)}</strong></td><td lang="en">${esc(v.term_en)}</td>${hasHint ? `<td>${esc(v.hint)}</td>` : ''}</tr>`).join('\n')}
</tbody>
</table></section>`;
        const digitsHtml = DIGITS ? catalogs.map((cat) => {
            const en = cat !== 'UBI';
            const rows = DIGITS[en ? 'SRC' : 'UBI'].map((w, n) => {
                const word = en ? w[0] : w;
                const spoken = en ? (w[1] ?? '') : '';
                return `<tr><td class="num"><strong>${n}</strong></td><td${en ? ' lang="en"' : ''}>${esc(word)}</td>${en ? `<td lang="en">${esc(spoken)}</td>` : ''}</tr>`;
            }).join('\n');
            return `<section><h2>${esc(str('spelling_digits', 'Ziffern ({catalog})', { catalog: cat }))}</h2>
<p>${esc(str(en ? 'spelling_digits_src' : 'spelling_digits_ubi', ''))}</p>
<table>
<thead><tr><th class="num" scope="col">Ziffer</th><th scope="col">Wort</th>${en ? '<th scope="col">Sprechform</th>' : ''}</tr></thead>
<tbody>
${rows}
</tbody>
</table></section>`;
        }).join('\n') : '';
        const source = `Content-Paket, Vokabeln Katalog ${catalogs.join(', ')} (Buchstaben)${DIGITS ? '; Ziffern wie im Funktraining der Lernplattform' : ''}`;
        addDoc({
            kind: 'spelling', course: code, file: `${slug(code)}/${slug(code)}-buchstabiertafel.pdf`, title: str('spelling_title', 'Internationale Buchstabiertafel'),
            caption: `Buchstaben${DIGITS ? ' und Ziffern' : ''} zum Nachschlagen (${catalogs.join(', ')})`,
            feature: 'radio_training', slot: 'spelling', source,
            intro: [['Inhalt', `${items.length} Buchstaben${DIGITS ? ' und 10 Ziffern' : ''}${hasHint ? ' mit Aussprachehilfe' : ''}`], ['Quelle', esc(source)]],
            body: lettersHtml + digitsHtml,
        });
    }
}

// ---------------------------------------------------------------------------
// Drucken
// ---------------------------------------------------------------------------
function loadPlaywright() {
    const tries = ['playwright', '/opt/node-tools/node_modules/playwright'];
    for (const t of tries) {
        try {
            return require(t);
        } catch {
            // nächster Versuch
        }
    }
    fail('Playwright nicht gefunden. NODE_PATH auf den node_modules-Ordner mit „playwright“ setzen.');
}

const countPages = (buf) => (buf.toString('latin1').match(/\/Type\s*\/Page(?![s\w])/g) || []).length;

async function main() {
    console.log(`Paket: ${path.relative(REPO, pkgDir)} (${manifest.package_id})`);
    console.log(`Ziel:  ${path.relative(REPO, outDir)}`);
    console.log(`Dokumente: ${docs.length}`);
    if (args['dry-run']) {
        for (const d of docs) {
            console.log(`  ${d.file}  ${d.title}`);
        }
        return;
    }
    const { chromium } = loadPlaywright();
    const browser = await chromium.launch();
    const context = await browser.newContext();
    const pkgRoot = path.resolve(pkgDir) + path.sep;
    await context.route(`${MEDIA_BASE}**`, (route) => {
        const rel = decodeURIComponent(new URL(route.request().url()).pathname.slice(1));
        const file = path.resolve(pkgDir, rel);
        return file.startsWith(pkgRoot) && fs.existsSync(file) ? route.fulfill({ path: file }) : route.fulfill({ status: 404 });
    });
    const pageObj = await context.newPage();
    let tagged = true;
    const result = [];
    try {
        for (const d of docs) {
            const file = path.join(outDir, d.file);
            fs.mkdirSync(path.dirname(file), { recursive: true });
            if (args.html) {
                fs.writeFileSync(file.replace(/\.pdf$/, '.html'), d.html);
            }
            await pageObj.setContent(d.html, { waitUntil: 'load' });
            const opts = {
                path: file, format: 'A4', printBackground: true, displayHeaderFooter: true,
                ...headerFooter(d),
                margin: { top: '20mm', bottom: '24mm', left: '16mm', right: '16mm' },
            };
            if (tagged) {
                try {
                    await pageObj.pdf({ ...opts, tagged: true, outline: true });
                } catch (e) {
                    if (!/tagged|outline/i.test(String(e))) {
                        throw e;
                    }
                    console.warn('Warnung: Diese Playwright-Version kann keine getaggten PDFs – weiter ohne Tags.');
                    tagged = false;
                }
            }
            if (!tagged) {
                await pageObj.pdf(opts);
            }
            const buf = fs.readFileSync(file);
            const entry = {
                file: d.file, kind: d.kind, course: d.course, course_name: courseName[d.course] || d.course,
                title: `${d.title} – ${courseName[d.course] || d.course}`, caption: d.caption,
                access: `${courseName[d.course] || d.course} · ${FEATURE_LABELS[d.feature] || d.feature}`,
                feature: d.feature, slot: d.slot, slot_label: SLOT_LABELS[d.slot] ?? d.slot,
                pages: countPages(buf), bytes: buf.length,
            };
            result.push(entry);
            console.log(`  ${d.file.padEnd(58)} ${String(entry.pages).padStart(4)} S. ${(buf.length / 1024).toFixed(0).padStart(6)} KB`);
        }
    } finally {
        await browser.close();
    }
    // Teilläufe (--only/--courses) ergänzen eine vorhandene Übersicht derselben Paketversion.
    const listFile = path.join(outDir, 'uebersicht.json');
    let documents = result;
    try {
        const prev = JSON.parse(fs.readFileSync(listFile, 'utf8'));
        if (prev.package_id === manifest.package_id && Array.isArray(prev.documents)) {
            const fresh = new Set(result.map((r) => r.file));
            documents = [...prev.documents.filter((r) => !fresh.has(r.file) && fs.existsSync(path.join(outDir, r.file))), ...result];
        }
    } catch {
        // keine oder unlesbare Übersicht – neu anlegen
    }
    const total = documents.reduce((s, r) => s + r.bytes, 0);
    const summary = {
        package_id: manifest.package_id, stand, generated_at: new Date().toISOString(), tagged,
        files: documents.length, bytes: total,
        per_kind: Object.fromEntries(ALL_KINDS.map((k) => [k, documents.filter((r) => r.kind === k).length]).filter(([, n]) => n)),
        documents,
    };
    fs.writeFileSync(listFile, `${JSON.stringify(summary, null, 2)}\n`);
    console.log(`Dieser Lauf: ${result.length} PDFs, ${(result.reduce((s, r) => s + r.bytes, 0) / 1048576).toFixed(1)} MB. In der Übersicht: ${documents.length} PDFs, ${(total / 1048576).toFixed(1)} MB.`);
    console.log(`Getaggt (barrierearme Struktur): ${tagged ? 'ja' : 'nein'}.`);
    console.log(`Abbildungen eingebunden: ${stats.imagesEmbedded}, fehlend (Hinweis „Abbildung im Lernbereich“): ${stats.imagesMissing}.`);
    console.log(`Übersicht für den Upload: ${path.relative(REPO, path.join(outDir, 'uebersicht.json'))}`);
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
