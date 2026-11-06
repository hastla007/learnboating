# Content-Konverter

Erzeugt aus dem Snapshot `content-source/boatingschool/` ein Content-Paket
(Format: [`schema/`](../../schema/README.md)). Keine Laravel-, Datenbank- oder
Netzwerkabhängigkeit; benötigt PHP ≥ 8.1 mit `intl`, `zip`, `simplexml`.

```bash
php tools/content-build/bin/build-package.php
```

Optionen: `--out=<ordner>` (Standard `build/`), `--previous=<voriger paketordner>`
(meldet entfernte IDs), `--no-zip`, `--source=<snapshot>`.

Der Konverter bildet das Ergebnis eines frischen `php artisan db:seed` der Quelle nach
(Reihenfolge wie `DatabaseSeeder`). Bewusste Abweichungen:

- 15 freie Kartenaufgaben ohne A–D-Antworten werden als `chart_task` (Status `draft`)
  übernommen statt verworfen;
- alle Texte werden getrimmt und Unicode-NFC-normalisiert;
- Navigationsaufgaben stehen einmal im Paket mit `course_codes` statt als Kopie je Kurs.

Videokurse liest der Konverter als reine Literale (Tokenizer, keine Ausführung) aus den
Seedern und bildet deren Logik nach (Kopien, Kapitel-Einschübe, Quizzuordnung nur einmal je
Lektion). Funktexte und Vokabeln kommen aus `database/data/funk_*.php` mit den Schlüsseln
von `App\Support\FunkTraining`.

Funkpraxis-Szenarien und Funksprüche der Gerätesimulation erzeugt der Generator
`App\Support\FunkPraxis` der Quelle; er ist reines PHP ohne Framework-Abhängigkeit und wird
unverändert aus dem Snapshot geladen (`scenario()`), damit die Ausgabe identisch bleibt.

Noch nicht konvertiert (siehe `not_converted` im Manifest): Bootsschul-Betriebsfunktionen.

## Tests

```bash
cd tools/content-build && composer install && vendor/bin/phpunit
```

Prüft Schema-Konformität aller Dateien, Zähler am gepinnten Commit, eindeutige IDs,
alle Querverweise, Prüfsummen, Determinismus und Quellregeln (Feedback, UBI-Ergänzung).

## Golden Master gegen Laravel

`golden-master/compare.php` vergleicht ein Paket Feld für Feld mit der Datenbank einer
frisch geseedeten Laravel-Kopie desselben Commits. Das Quellrepository wird dabei nicht
verändert – die Kopie läuft lokal:

1. Quellrepo zum Snapshot-Commit in einen temporären Ordner klonen,
   `composer install --no-scripts`.
2. PostgreSQL 16, Rollen und Datenbank wie in der Quell-README anlegen, `.env` mit
   `APP_ENV=production` (keine Demodaten).
3. `php artisan migrate --database=pgsql_admin --force` und
   `php artisan db:seed --database=pgsql_admin --force`.
4. Vergleich:

```bash
BS_DSN="pgsql:host=127.0.0.1;dbname=boatingschool" BS_USER=boatingschool BS_PASSWORD=… \
  php tools/content-build/golden-master/compare.php build/<package_id>
```

Mit `BS_LARAVEL_DIR=<Laravel-Kopie>` werden zusätzlich Funktexte und Vokabeln gegen
`App\Support\FunkTraining` verglichen.

Ergebnis für Commit `136a243`: keine Abweichungen außer den 15 `chart_task` (inkl. 90
Videokapiteln, 449 Lektionen, 1.349 Quizzuordnungen, 43 Funktexten, 410 Vokabeln).
Die CI führt diesen Abgleich automatisch aus (`.github/workflows/ci.yml`).
