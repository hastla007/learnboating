# Sicherheit: Entscheidungen des Betreibers (To-do T35)

Drei Fragen. Je Frage: wie es heute ist, was sich ändern ließe, unser Vorschlag.

## 1. Login-Grenzen

**Heute** (`src/Security/LoginGuard.php`): Web und App teilen sich die Zähler. In 15 Minuten sind erlaubt:

| Zähler | Fehlversuche |
|---|---|
| je IP-Adresse | 10 |
| je Konto und IP-Adresse | 5 |
| je Konto insgesamt | 30 |

- Die Grenze je Konto ist absichtlich höher. Sonst könnte jemand mit 5 falschen Passwörtern ein fremdes Konto
  aussperren.
- Eine erfolgreiche Anmeldung setzt den Zähler „Konto und IP“ zurück.
- Ist ein Sicherheits-Plugin mit eigener Login-Sperre aktiv (Wordfence, Limit Login Attempts (Reloaded), Solid
  Security, All In One WP Security, Loginizer), nimmt sich die Sperre des Lern-Plugins zurück.
- Ändern ohne Programmierung: Filter `bootspruefung_login_limits` (Schlüssel `ip`, `login_ip`, `login`).

**Seit T41 („bekanntes Gerät“):** Wer sich auf einem Gerät schon erfolgreich angemeldet oder dort das Passwort
zurückgesetzt hat, bekommt einen signierten Nachweis (Web: Cookie, App: `device_token`, 180 Tage, mit
Passwortänderung ungültig). Für diese Person zählen nur die 5 Fehlversuche je Konto und IP. Die Sperre je IP
(Fahrschul-WLAN, Mobilfunk, Proxy) und die Sperre je Konto (Angreifer mit vielen IPs) treffen sie nicht mehr.
Ein Passwort-Reset setzt die Kontozähler zurück. IPv6 zählt je /64-Netz. Ein nicht eingetragener Proxy wird im
Systemstatus gemeldet.

**Frage:** Passen 10 / 5 / 30 in 15 Minuten? Sollen Konten mit Verwaltungsrechten eigene Grenzen bekommen?

## 2. SVG im Content-Import

**Heute:** Der Import lehnt SVG ab, ebenso HTML und Skripte. Erlaubt sind PNG, JPG, GIF, WebP, MP3, M4A, MP4, WebM
und PDF. Grund: Eine SVG-Datei kann Skripte enthalten, die auf der eigenen Domain laufen würden. Das heutige
Content-Paket enthält nur PNG.

**Möglich:** SVG über den Filter `bootspruefung_media_allowed_types` zulassen. Sicherer ist es, SVG-Grafiken
beim Erstellen des Pakets in PNG umzuwandeln.

**Vorschlag:** SVG weiter ablehnen. Falls Grafiken nur als SVG vorliegen, beim Paketbau in PNG umwandeln.

**Frage:** Einverstanden?

## 3. Zwei-Faktor-Anmeldung für Admins

**Heute:** Nicht eingebaut. WordPress selbst bietet keine Zwei-Faktor-Anmeldung.

**Vorschlag:** Für alle Konten mit Verwaltungsrechten ein eigenes Zwei-Faktor-Plugin einsetzen (z. B. „Two Factor“
aus dem WordPress-Plugin-Verzeichnis). Für Kunden nicht verpflichtend. Vor dem Livegang auf Staging prüfen, ob
Anmeldung, Passwort-Reset und App-Login damit funktionieren. Die App meldet sich nur mit Benutzername und Passwort
an; wie sich das Plugin beim App-Login eines Admin-Kontos verhält, auf Staging prüfen.

**Frage:** Zwei-Faktor für Admins einführen, und mit welchem Plugin?
