# BoatingSchool Mobile API für WordPress

Das Plugin stellt die API-Routen aus `openapi-wordpress-mobile-v1.yaml` im Namespace `/wp-json/boatingschool/v1` bereit. Es verwendet WordPress-Konten, eigene Content-Types für Kursinhalte und LearnDash-Kurse als optionalen Kurs-Adapter.

## Installation

1. Das Verzeichnis `wp-plugin/boatingschool-mobile-api/` nach `wp-content/plugins/` kopieren.
2. In WordPress **BoatingSchool Mobile API** aktivieren. Beim Aktivieren wird die Tabelle für widerrufbare Gerätesitzungen angelegt.
3. Unter **Einstellungen → BoatingSchool API** Tenant-Slug, Shop-URL und Einlösecodes im Format `CODE=KURS-ID` setzen.
4. Kurse, Module, Fragen, Lektionen und Navigationsaufgaben in der WP-Administration anlegen und veröffentlichen. Kurs-IDs werden in den Metadaten der Module/Fragen/Lektionen eingetragen.
5. Mit HTTPS betreiben, Permalinks aktivieren und den Login-Endpunkt gegen Brute-Force drosseln (Webserver/WAF oder vorhandenes Security-Plugin).

## Inhaltsmodell

- **Kurs**: eigener Typ `bsm_course`, außerdem werden vorhandene `sfwd-courses` und `course` erkannt. Im Metabox-Bereich „BoatingSchool API“ Zugangsmodus, Tenant, Shop-Link und Prüfungsregeln pflegen. `free` gibt den Kurs frei; andere Werte erfordern LearnDash-Zugang oder einen eingelösten Code.
- **Modul**: `bsm_module`, mit Kurs-ID und optionaler Themenkennung.
- **Frage**: `bsm_question`, Fragetext im Editor, Antwortoptionen als JSON `[{"id":"a","text":"Antwort A"}]`, korrekte IDs als JSON-Array ` ["a"] `, plus Kurs-/Modul-ID und Thema.
- **Video-Lektion**: `bsm_lesson`, Kurs-ID, Medien-URL und Dauer. Leere Medien-URL wird als nicht abspielbarer Platzhalter ausgegeben.
- **Navigationsaufgabe**: `bsm_navigation_task`, Kurs-ID, Teile als JSON im Options-Feld und Musterlösung im Lösungsfeld.

Eigene LMS-Systeme können `boatingschool_mobile_course_post_types` und `boatingschool_mobile_course_payload` filtern. LearnDash-Enrolments werden direkt geprüft; Fragen und Module müssen derzeit in das BoatingSchool-Inhaltsmodell importiert oder über die Adapter erweitert werden.

## API und Authentifizierung

Basis: `https://<wordpress-host>/wp-json/boatingschool/v1`. Login erwartet E-Mail, Passwort und Gerätename. Das Plugin stellt kurzlebige Bearer-Tokens (15 Minuten) und rotierende Refresh-Tokens (30 Tage Inaktivität, maximal 90 Tage) aus; nur Token-Hashes werden in der WP-Datenbank gespeichert. Geschützte Routen verlangen `Authorization: Bearer …` sowie `X-Tenant-Slug` (Standard-Tenant, wenn ausgelassen). WordPress-Passwort-Reset-Mails werden verwendet; E-Mail-Verifikationsmails werden durch das Plugin mit einmaligem Zufallstoken bestätigt.

Die API umfasst Login/Refresh/Logout, Sitzungsverwaltung, Profil, Kurse/Module, Smarttrainer/Versuche, Favoriten/Sammlungen/Fortschritt, Prüfungen, Video/Fortschritt, Navigation, Code-Einlösung und Datenschutzexport. Die genaue Request-/Response-Spezifikation ist `openapi-wordpress-mobile-v1.yaml`.

## Einlösecodes und Zugriff

Codes werden in den Plugin-Einstellungen als `CODE=KURS-ID` gepflegt und nach Einlösung dem Nutzerzugang hinzugefügt. Jeder Code ist einmalig. LearnDash-Enrolments werden als Kurszugang akzeptiert. Ein eigener WooCommerce- oder CRM-Kaufabgleich ist nicht enthalten; die Shop-URL bleibt ein externer Link.

## Betriebsgrenzen, die vor Produktivstart zu konfigurieren sind

Die Implementierung ist auf ein WordPress-Projekt je Tenant ausgelegt; der Tenant-Slug ist Kontext und Membership-Prüfung, kein vollständiger Multi-Tenant-SaaS-Isolationsmechanismus. Inhalte, Kursberechtigungen, Exam-Regeln, Codes und URLs müssen in der WP-Administration gepflegt werden. Prüfungsregeln haben ein Standardprofil und können pro Kurs als JSON überschrieben werden. E-Mail-Transport, WP-Uploads, Reverse-Proxy-Weitergabe des `Authorization`-Headers, HTTPS, Backups und Rate-Limits hängen von der Hosting-Konfiguration ab.

Die ZIP enthält zusätzlich den ursprünglichen Konzepttext und UI-Entwurf. Wo darin Laravel als Backend genannt ist, beschreibt das den früheren Zielentwurf; diese WordPress-Ergänzung ist der aktuelle API-Pfad.
