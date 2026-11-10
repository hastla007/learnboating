# Staging (Docker)

Eine vollständige Testinstallation für die Abnahme, mit genau den Versionen des Zielsystems:

| Baustein | Version |
| --- | --- |
| PHP | 8.5 (Apache) |
| WordPress | 7.1.4 |
| MariaDB | 12.3.3 |
| WooCommerce | 11.2.2 |
| Plugin / Theme | `bootspruefung-learning`, `kurswasser` aus diesem Repo |

Beim Start wird automatisch eingerichtet:

- WordPress-Installation (Deutsch, sofern das Sprachpaket geladen werden kann), Permalinks `/%postname%/`, Suchmaschinen ausgesperrt
- WooCommerce mit Euro, Deutschland und der Testzahlart „Überweisung (Staging)“ (es wird kein echtes Geld bewegt)
- Plugin und Theme aktiviert, Datenbankschema aktuell
- Content-Paket aus `content-source/boatingschool` gebaut und importiert
- Inhalte und Prüfungsregelwerke für die Abnahme freigegeben (abschaltbar, siehe unten)
- Seiten des Themes, alle Shop-Produkte (veröffentlicht) und das kostenlose Schnupper-Paket
- Lerninhalte nur nach Kauf (`entitled_only`), wie im Livebetrieb
- optional ein Demo-Konto `demo_lernende` ohne Kauf

## Starten

Voraussetzung ist Docker mit Compose v2 (Docker Desktop oder ein Linux-Server mit `docker compose`).

```bash
cd staging
cp .env.example .env
```

In `.env` mindestens `DB_PASSWORD` und `STAGING_ADMIN_PASSWORD` setzen. Lange Zufallswerte gehen zum Beispiel so:

```bash
openssl rand -hex 16
```

Dann starten:

```bash
docker compose up -d
```

Der Container `setup` richtet alles ein und beendet sich danach (beim ersten Mal etwa 1–3 Minuten). Den Fortschritt zeigt:

```bash
docker compose logs -f setup
```

Danach:

- Website: `http://localhost:8080`
- Admin: `http://localhost:8080/wp-admin/` (Benutzer aus `STAGING_ADMIN_USER`, Standard `admin`)
- Lernbereich: `http://localhost:8080/bootsscheine/`

## Auf einem Server

1. Repo auf den Server holen (`git clone …`, Branch auschecken) und `.env` wie oben anlegen.
2. **Mit eigener Domain und HTTPS (empfohlen):** DNS-Eintrag (A/AAAA) auf den Server, Ports 80 und 443 offen. In `.env`:

   ```
   STAGING_DOMAIN=staging.example.de
   STAGING_URL=https://staging.example.de
   ```

   Dann starten:

   ```bash
   docker compose --profile https up -d
   ```

   Caddy holt das Zertifikat automatisch und setzt `X-Robots-Tag: noindex`.

   **App-Schnittstelle hinter Caddy:** WordPress sieht als Client-Adresse den Caddy-Container. Damit die Anmelde-Limits der App je echter Client-IP zählen, unter Admin → Bootsprüfung → Mobile App → „Vertrauenswürdige Proxys“ das Docker-Netz des Projekts eintragen (seit App-API 1.4.0 wird standardmäßig keinem Proxy vertraut). Subnetz ermitteln:

   ```bash
   docker network inspect "$(basename "$PWD")_default" --format '{{(index .IPAM.Config 0).Subnet}}'
   ```

   (z. B. `172.18.0.0/16`; bei abweichendem Projektnamen `docker network ls` ansehen). Ohne Eintrag teilen sich alle Testkunden die Limits der Caddy-Adresse.
3. **Ohne Domain:** `STAGING_BIND=0.0.0.0` und `STAGING_URL=http://<server-ip>:8080` setzen. Das ist nur für kurze Tests gedacht, weil Passwörter dabei unverschlüsselt übertragen werden.

Die Staging-Seite ist für Suchmaschinen gesperrt, aber öffentlich erreichbar, wenn sie im Netz hängt. Wer sie verstecken möchte, schützt sie zusätzlich (Firewall/VPN oder Basic-Auth im eigenen Reverse-Proxy).

## Aktualisieren

Nach `git pull` laufen Plugin und Theme sofort mit dem neuen Stand, weil sie direkt aus dem Repo eingebunden sind. Den neuen Content und Datenbank-Migrationen übernimmt:

```bash
docker compose run --rm setup
```

Die Einrichtung ist wiederholbar:

- Unveränderte Inhalte bleiben unverändert.
- Rechtstext-Vorlagen werden übernommen, solange die Seiten nicht von Hand bearbeitet wurden (`wp kurswasser legal`), und die Widerrufsfunktion von WooCommerce wird eingeschaltet.
- „Zugang ohne Shop“, die Shop-Grundeinstellungen und der Veröffentlichungsstatus der Produkte werden nur beim ersten Start gesetzt.
- Preise, Namen, Kategorien und Zuordnungen der Produkte werden bei jedem Lauf mit dem Katalog abgeglichen. Preise deshalb unter Admin → Bootsprüfung → Preise ändern, nicht direkt in WooCommerce (Angebotspreise in WooCommerce bleiben erhalten).

Ein neues WordPress-Image aktualisiert den WordPress-Kern im Volume nicht automatisch. Für eine neue WordPress-Version das Staging neu aufbauen (`docker compose down -v`, dann `up -d`).

## Häufige Aufgaben

Eine Bestellung als bezahlt markieren (Testzahlart Überweisung → Zugang wird freigeschaltet), entweder im Admin unter WooCommerce → Bestellungen („In Bearbeitung“) oder per Befehl:

```bash
docker compose run --rm --entrypoint wp setup eval 'wc_get_order(123)->payment_complete();'
```

Beliebige WP-CLI-Befehle:

```bash
docker compose run --rm --entrypoint wp setup bootspruefung status
```

Fehlerprotokoll einschalten: `WP_DEBUG=1` in `.env`, dann `docker compose up -d`. Das Protokoll liegt außerhalb des Webroots und lässt sich so ansehen:

```bash
docker compose exec wordpress tail -n 50 /tmp/wp-debug.log
```

Alles stoppen (Daten bleiben):

```bash
docker compose down
```

Alles löschen und neu beginnen:

```bash
docker compose down -v
```

## Einstellungen (`.env`)

| Variable | Bedeutung |
| --- | --- |
| `DB_PASSWORD`, `STAGING_ADMIN_PASSWORD` | Pflicht |
| `STAGING_URL` | Adresse im Browser (ohne `/` am Ende) |
| `STAGING_PORT`, `STAGING_BIND` | Port und Netzwerkschnittstelle (Standard nur lokal `127.0.0.1:8080`) |
| `STAGING_DEMO_PASSWORD` | legt `demo_lernende` (ohne Kauf) an |
| `STAGING_RELEASE_RIGHTS` | `1` gibt Inhalte mit ungeklärtem Rechtestatus und die Prüfungsregelwerke für die Abnahme frei; `0` zeigt nur bereits freigegebene Inhalte (wie beim Livestart vor der Rechteklärung). Freigaben werden nicht zurückgenommen: `0` wirkt nur bei einem Neuaufbau (`docker compose down -v`) |
| `STAGING_DOMAIN` | Domain für das HTTPS-Profil |
| `IMAGE_REGISTRY` | nur falls Docker Hub nicht erreichbar oder gedrosselt ist, z. B. `mirror.gcr.io/library` |
| `MAILPIT_PORT`, `MAILPIT_IMAGE` | Port und Image des Test-Postfachs (Standard `8025`, `docker.io/axllent/mailpit:v1.27`) |

Ohne Internet im Container (z. B. hinter einem Firmen-Proxy) kann WooCommerce nicht geladen werden. Dann die Datei `woocommerce.zip` der Version 11.2.2 von GitHub herunterladen und als `staging/woocommerce-11.2.2.zip` ablegen (wird nicht eingecheckt).

## Abnahme-Checkliste

- [ ] Startseite, Kursseiten und Shop laden; Preise stimmen (Admin → Bootsprüfung → Preise)
- [ ] Registrieren, Schnupper-Paket nutzen (10 Fragen je Modul, 1 Prüfungsbogen, erste Video-Lektion)
- [ ] Kurs kaufen (Block-Checkout, Überweisung), Bestellung als bezahlt markieren, Zugang unter „Mein Lernen“ aktiv
- [ ] Theorie-Stufe kaufen: Video-Upgrade im Checkout und im Lernbereich angeboten; Komplett-Stufe ohne Upgrade-Angebot
- [ ] Smarttrainer, Prüfungssimulation mit Ergebnisverlauf, Favoriten & Sammlungen
- [ ] Navigations-, Praxis- und Funktrainer, Muster-Funksprüche, Buchstabiertafel, Funkgerät-Simulator
- [ ] Gruppenpaket kaufen und Mitglieder einladen
- [ ] Geld-zurück-Anfrage und Erstattung (Zugang endet)
- [ ] „Vertrag hier widerrufen“ (Fußzeile und „Mein Lernen“): WooCommerce-Formular ausfüllen, prüfen, „Widerruf bestätigen“; Eingangsbestätigung im Test-Postfach, Notiz in der Bestellung
- [ ] PDFs in der Mediathek hochladen und dem Bereich zuordnen (Punkt 34)
- [ ] Mobil (Smartphone) durchklicken
- [ ] Rechtstexte und Firmenangaben (Impressum, AGB, Widerruf, Datenschutz) eingesetzt; Platzhalter „XXXX“ ersetzt

E-Mails (Bestellbestätigung, Gruppeneinladungen, Geld-zurück, Passwort vergessen) gehen nicht ins Internet. Sie landen im Test-Postfach **Mailpit** unter `http://localhost:8025` (auf dem Server per SSH-Tunnel, z. B. `ssh -L 8025:localhost:8025 server`).

Zeitgesteuerte Aufgaben (Ablauf von Zugängen und Prüfungen, WooCommerce-Aufgaben) erledigt der Dienst `cron` jede Minute.
