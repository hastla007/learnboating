# Installation und Updates (To-do T91)

Für Betreiber und alle, die die Website einrichten oder aktualisieren. Regeln für Versionsnummern und den Rückweg
(Downgrade): `docs/release-regel.md`. Server und Datenbank: `docs/server-einrichtung.md`. Sicherung vor jedem Update:
`docs/backup-restore.md`.

## Die Pakete

Jede Auslieferung besteht aus vier ZIP-Dateien und einer Datei mit Prüfsummen. Sie liegen dauerhaft auf GitHub unter
**Releases** (Tag `v<Plugin-Version>`, z. B. `v0.14.0`). Theme und Add-ons tragen ihre eigene Version im Dateinamen.

| ZIP-Datei | Was | Ordner nach dem Einspielen | Pflicht? |
|---|---|---|---|
| `bootspruefung-learning-<version>.zip` | Lern-Plugin „Bootsprüfung Lernplattform“ | `wp-content/plugins/bootspruefung-learning/` | ja |
| `kurswasser-<version>.zip` | Theme „Kurswasser“ (ohne Lern-Plugin, siehe unten) | `wp-content/themes/kurswasser/` | ja (Website-Design) |
| `learnboating-affiliate-connector-<version>.zip` | Add-on „Partnerprogramm-Connector“ (verbindet mit WP Affiliate Captain) | `wp-content/plugins/learnboating-affiliate-connector/` | nur mit Partnerprogramm |
| `learnboating-voucher-connector-<version>.zip` | Add-on „Kursgutschein-Connector“ (verbindet mit Voucher Captain) | `wp-content/plugins/learnboating-voucher-connector/` | nur mit Kursgutscheinen |
| `SHA256SUMS` | Prüfsummen der vier ZIP-Dateien | – | – |

Die Ordnernamen müssen genau so lauten. WordPress erkennt ein Update daran, dass der Ordner schon existiert; ein
anderer Name (z. B. `bootspruefung-learning-0.14.0/`) legt ein zweites Plugin an.

Die ZIP-Dateien enthalten keine Tests, Werkzeuge oder Entwicklerdateien. Das Lern-Plugin braucht keine weiteren
Bibliotheken (kein `composer install` auf dem Server).

**Prüfsummen prüfen** (empfohlen, im Ordner mit den heruntergeladenen Dateien): `sha256sum -c SHA256SUMS`. Jede Zeile
muss „OK“ zeigen.

### Benötigte Plugins von anderen

| Plugin | Ordner | Woher | Wofür |
|---|---|---|---|
| WooCommerce | `woocommerce/` | wordpress.org (über „Kurswasser einrichten“ oder Plugins › Installieren) | Shop, Kasse, Freischaltung |
| Yoast SEO (optional) | `wordpress-seo/` | wordpress.org | SEO-Angaben der Inhaltsseiten |
| Voucher Captain | `voucher-captain/` | Repository `hastla007/wp-couponmaster`; Paket mit dessen `bin/build.sh` (ergibt `build/voucher-captain.zip`) | Kursgutscheine (nur mit dem Kursgutschein-Connector) |
| WP Affiliate Captain | `wp-affiliate-captain/` | nicht in diesem Repository; Bezug klärt der Betreiber | Partnerprogramm (nur mit dem Partnerprogramm-Connector) |

Die Ordnernamen der „Captain“-Plugins stehen in den Kopfzeilen der Add-ons (`Requires Plugins:`). WordPress aktiviert
ein Add-on erst, wenn die dort genannten Plugins aktiv sind.

## Kompatibilität

| | Unterstützt | In der CI geprüft |
|---|---|---|
| PHP | 8.1 bis 8.5 | 8.1 (Untergrenze) und 8.5 (Zielsystem) |
| WordPress | ab 7.0 (getestet bis 7.1) | 7.0.0 (Untergrenze) und 7.1.4 (Zielsystem) |
| WooCommerce | ab 11.2 (getestet bis 11.2, `WC tested up to: 11.2`); optional – ohne WooCommerce läuft das Plugin ohne Shop | 11.2.2; 11.3 Beta nur zur Information |
| MariaDB | ab 10.11 | 10.11 und 12.3.3 (Zielsystem) |
| MySQL | nicht festgelegt | nicht getestet – vor dem Einsatz auf Staging prüfen |
| Theme Kurswasser | braucht das Lern-Plugin ab 0.13.0 | Versionsvertrag (`themes/kurswasser/tests/learning-compat-test.php`) |
| Add-ons | brauchen das Lern-Plugin ab 0.13.0 bzw. mit Grant-Vertrag 1.x | Connector-Tests |

Die Datenbank muss InnoDB mit Fremdschlüsseln können (Systemstatus des Plugins zeigt das an). Zielsystem laut
`docs/offene-punkte.md`, Punkt 20: PHP 8.5, WordPress 7.1.4, MariaDB 12.3.3, WooCommerce 11.2.2.

## Erstinstallation

Reihenfolge:

1. WordPress installieren, Sicherung einrichten (`docs/backup-restore.md`).
2. **Theme** hochladen: Design › Themes › Theme hinzufügen › Theme hochladen › `kurswasser-<version>.zip` ›
   Jetzt installieren › Aktivieren. WordPress öffnet danach „Kurswasser einrichten“.
3. In „Kurswasser einrichten“, Schritt 1: WooCommerce (und optional Yoast SEO) und das **Lern-Plugin** installieren.
   Das Theme-Paket enthält das Lern-Plugin **nicht** (T106: eine ZIP im Theme-Ordner wäre über die Adresse des
   Themes für jeden abrufbar). Der Assistent bietet dafür „Plugin-ZIP hochladen“ an (`bootspruefung-learning-<version>.zip`
   aus demselben Release); danach Seite neu laden – das installierte Plugin wird erkannt und dort aktiviert. Alternativ
   die Quelle in `wp-config.php` hinterlegen: `define( 'KURSWASSER_LEARNING_PLUGIN_ZIP', '/pfad/außerhalb/des/webroots/bootspruefung-learning-<version>.zip' );`
   (Pfad oder URL). WooCommerce wird immer vor dem Lern-Plugin aktiviert.
   Ohne Assistent: zuerst WooCommerce, dann Plugins › Installieren › Plugin hochladen ›
   `bootspruefung-learning-<version>.zip`.
   Liegt aus einer älteren Theme-Version noch `wp-content/themes/kurswasser/plugins/*.zip` auf dem Server, warnen
   Assistent und Admin-Hinweis und bieten „Datei löschen“ an.
4. Schritt 2 und 3 des Assistenten (Inhaltsseiten, Shop einrichten), danach das Content-Paket importieren
   (Bootsprüfung › Import).
5. Nur bei Bedarf die Add-ons: zuerst das „Captain“-Plugin (Voucher Captain bzw. WP Affiliate Captain), dann das
   passende Add-on über Plugin hochladen. Einrichtung: `learnboating-voucher-connector/README.md` bzw.
   `learnboating-affiliate-connector/README.md`.

## Updates einspielen

Updates kommen nicht über die automatische Update-Funktion von WordPress (`Update URI: false` in allen vier
Paketen). Sie werden von Hand als ZIP hochgeladen. Damit du von neuen Versionen erfährst, kannst du unter
Bootsprüfung › Einstellungen › Betrieb „Auf neue Versionen prüfen“ einschalten (Standard aus): Höchstens einmal am Tag
fragt die Website die GitHub-Releases ab (ohne Daten der Website); eine neuere Version erscheint als Admin-Hinweis und
im Systemstatus („Neue Version“) mit Link zur Release-Seite – installiert wird nichts automatisch. Sicherheitsupdates
erscheinen als Warnung (`docs/release-regel.md`, „Sicherheitsmitteilung“).

1. **Vorher:** Änderungsprotokoll lesen (Text des Releases bzw. `CHANGELOG.md` im Paket; Plugin-Version, Schema- und
   App-API-Version). Sicherung von Datenbank und Uploads (`docs/backup-restore.md`).
2. **Erst Staging, dann Live** (`docs/release-regel.md`, Ablauf einer Auslieferung).
3. Reihenfolge, wenn mehrere Pakete neu sind: **Lern-Plugin → Theme → Add-ons.** Theme und Add-ons prüfen die
   Plugin-Version; ein zu altes Plugin schaltet sie in den Betrieb ohne Plugin bzw. kontrolliert inaktiv (mit
   Hinweis im Admin), es geht aber nichts kaputt.
4. Plugin: Plugins › Installieren › **Plugin hochladen** › ZIP auswählen › Jetzt installieren. WordPress meldet,
   dass das Plugin schon installiert ist, und zeigt alte und neue Version gegenüber: die Schaltfläche zum
   **Ersetzen** der aktuellen Version durch die hochgeladene wählen. Nicht vorher deaktivieren oder löschen (Löschen kann je nach Einstellung Daten entfernen).
5. Theme: Design › Themes › Theme hinzufügen › Theme hochladen › ZIP › ebenso **Ersetzen** wählen.
6. Add-ons: wie das Plugin (Schritt 4).
7. **Nachher:** einmal den Admin-Bereich öffnen. Die Datenbank-Migration läuft dabei (oder per Cron bzw.
   `wp bootspruefung migrate`); bis dahin antwortet die Lern-API auf schreibende Anfragen mit „Wartung“.
   Bootsprüfung › Systemstatus: Schema „aktuell“, Abschnitt „Wartung“ ohne Fehler. Mit neuem Theme: Hinweis
   „Inhaltsseiten“ im Dashboard prüfen (`docs/theme-livegang.md`).
   Schlägt die Migration fehl, zeigt der Admin Administratoren einen einzigen Hinweis „Datenbank-Aktualisierung
   fehlgeschlagen“ mit Knopf „Systemstatus öffnen“, den Schritten (Systemstatus prüfen, `wp bootspruefung migrate`,
   sonst Hoster bzw. technische Betreuung) und der Meldung der Datenbank unter „Technische Angaben“ zum Aufklappen
   (T120). Häufige Ursachen: fehlende Rechte des Datenbank-Nutzers (CREATE, ALTER, REFERENCES), voller Speicher,
   keine InnoDB-Unterstützung.

Mit WP-CLI geht dasselbe: `wp plugin install bootspruefung-learning-<version>.zip --force` bzw.
`wp theme install kurswasser-<version>.zip --force`, danach `wp bootspruefung migrate`.

Die CI prüft genau diesen Weg: Job „Update von der Vorversion“ installiert die Vorversion (den letzten Release-Tag
`v*` vor dem geprüften Commit, `git describe --tags --abbrev=0`; solange es keinen Tag gibt, 0.13.0 als fester Commit
`004e728`), legt Lerndaten an,
spielt die neue ZIP darüber ein (ohne erneute Aktivierung) und prüft Migration und Daten
(`tools/release/upgrade-check.php`). Job „Auslieferungs-ZIP“ installiert Plugin, Theme und Add-ons aus den ZIPs und
lässt die Basistests laufen.

## Zurück auf eine ältere Version

Siehe `docs/release-regel.md`, Abschnitt „Downgrade“: Bei gleicher Schema-Version genügt die ältere ZIP (wie oben
„ersetzen“); bei neuerer Schema-Version nur mit dem Datenbank-Backup von vor dem Update.

## Pakete bauen (für Entwickler)

```bash
tools/release/build-zips.sh                     # Stand HEAD → build/release/
tools/release/build-zips.sh --rev <commit>      # älterer Stand (z. B. für ein Downgrade vor dem ersten Release)
tools/release/build-zips.sh --out /tmp/zips     # anderer Zielordner
tools/release/build-zips.sh --tag v0.15.0       # wie der Release-Workflow: Tag ⇔ Plugin-Version, Versionen erhöht?
```

Das Skript nimmt nur gespeicherte Stände (Commit), prüft, dass Versionen im Kopf, in den Konstanten, in `readme.txt`
und in den Änderungsprotokollen übereinstimmen, und dass in den ZIPs nur ein Ordner mit dem richtigen Namen und keine
Entwicklerdateien liegen. Verboten sind außerdem (T106) die Markdown-Quellen der Inhaltsseiten (`content/src/`,
`_moderation/`) und eingebettete ZIP-Dateien – das Theme-Paket enthält das Lern-Plugin nicht. Das Plugin-ZIP enthält
eine Kopie von `CREDITS.md`. Gleicher Commit ergibt gleiche Prüfsummen.

Mit `--tag` prüft das Skript zusätzlich gegen den vorigen Release-Tag: Hat sich der Ordner des Plugins, des Themes oder
eines Add-ons seit diesem Tag geändert (`git diff --quiet <voriger-tag> -- <ordner>`), die Version im Dateikopf aber
nicht, bricht es ab. Ohne vorigen Tag wird diese Prüfung übersprungen.

Es gibt nur diesen einen Bauweg. Das frühere `themes/kurswasser/tools/build-plugin-zip.sh` ist entfernt; ein
einzelnes Plugin-ZIP kommt aus `tools/release/build-zips.sh` (bzw. dem CI-Artefakt `release-zips-<commit>`).

**Release anlegen:** Versionen nach `docs/release-regel.md` erhöhen, Änderungsprotokolle ergänzen, CI grün,
dann Tag setzen: `git tag v<Plugin-Version>` und `git push origin v<Plugin-Version>`. Der Workflow
`.github/workflows/release.yml` prüft, dass die CI für diesen Commit grün war und der Tag zur Plugin-Version passt,
baut die ZIPs und legt das GitHub-Release mit ZIPs, `SHA256SUMS` und Änderungen an. Von Hand gestartet
(Actions › Release › Run workflow) baut er nur die ZIPs als Artefakt, ohne Release.

Ein Tag gilt für alle vier Pakete. Ändert sich nur das Theme oder ein Add-on, ist das trotzdem eine Auslieferung;
nach der Release-Regel bekommt dann auch das Lern-Plugin eine neue PATCH-Version, damit der Tag eindeutig bleibt.

## Prüfungen in der CI vor einem Release (T90, T94)

| Job | Prüft |
|---|---|
| WordPress-Plugin (Matrix) | alle Integrations- und Browser-Tests; PHP 8.5/WP 7.1.4 (Ziel), WooCommerce 11.3 Beta (informativ), Untergrenze PHP 8.1/WP 7.0.0/MariaDB 10.11 |
| Auslieferungs-ZIP | baut die ZIPs mit `tools/release/build-zips.sh` (Artefakt `release-zips-<commit>`), installiert sie statt der Repo-Ordner und lässt Rauchtest, Import/Lernen, Zugänge, Betrieb und den Versionsabgleich des Themes laufen |
| Update von der Vorversion | Vorversion = letzter Release-Tag vor dem Commit (`git describe --tags --abbrev=0`), ohne Tag 0.13.0 (fester Commit `004e728`), mit Daten, dann die neue ZIP darüber, Migration und Datenbestand prüfen, Tests weiter grün |
| Statische Analyse | PHPStan Stufe 5 mit WordPress- und WooCommerce-Stubs (`bootspruefung-learning/phpstan.neon.dist`), WordPress Coding Standards: Sicherheitsregeln als Fehler (`phpcs.xml.dist`), alle übrigen Regeln nur als Übersicht |
| Voucher Captain | Connector-Abnahme mit Voucher Captain aus dem externen Repo (fester Commit, informativ, darf scheitern) |

Bekannte Funde stehen in Baselines (`bootspruefung-learning/phpstan-baseline.neon`, `phpcs-baseline.xml`); neue
Funde machen die CI rot. Die Baselines sollen nur kleiner werden. Lokal:

```bash
cd bootspruefung-learning
composer install          # nur Entwicklerwerkzeuge (require-dev); nicht auf dem Server
composer phpstan
composer phpcs            # Sicherheitsregeln (blockierend)
composer phpcs-style      # alle WordPress-Regeln als Übersicht
rm -rf vendor             # danach entfernen: ohne vendor/ nutzt das Plugin seinen eigenen Autoloader
```
