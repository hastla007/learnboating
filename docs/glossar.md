# Glossar: feste Begriffe im Admin-Bereich (T100)

Die Verwaltungsseiten des Lern-Plugins verwenden nur diese Begriffe. Dieselbe Liste steht im Admin unter
Bootsprüfung › Übersicht › „Hilfe“ › „Begriffe (Glossar)“ zum Aufklappen (Quelle im Code: `Admin/Glossary.php`).
Interne Schlüssel und Kennungen (z. B. `question_bank`, `entitlement`, Option-Namen) bleiben unverändert; geändert
wurden nur sichtbare Texte.

| Begriff | Bedeutung | Statt (nicht mehr verwenden) |
|---|---|---|
| **Zugang** | Recht einer Person, eine Funktion eines Kurses zu nutzen – mit Status, Laufzeit und Quelle (Bestellung, manuell, Gruppe, Kursgutschein). | Entitlement, Berechtigung |
| **Funktion** | Ein Lernbereich eines Kurses, z. B. Fragen lernen, Prüfungssimulation, Videokurs, Navigationstrainer. | Feature; „Umfang“ für eine einzelne Funktion |
| **Umfang** | Was genau freigeschaltet ist: bei Produkten und Gruppenlizenzen die Kurse mit ihren Funktionen, bei kostenlosen Bereichen „ganz“ oder ein Teilumfang. | Scope |
| **Teilumfang** | Begrenzter kostenloser Teil, z. B. die ersten 10 Fragen je Modul oder der erste Prüfungsbogen. | Limit |
| **Laufzeit** | Wie lange ein Zugang ab Beginn gilt. Wird vorbelegt; „ohne Ablauf (dauerhaft)“ nur ausdrücklich (`docs/admin-bereich.md`). | Dauer, „leer = dauerhaft“ |
| **Freischalten** | Einer Person einen Zugang geben – durch Kauf, Kursgutschein, Gruppenplatz oder manuell („Zugang manuell freischalten“). | Zugang freigeben, Grant |
| **Zeitversetzte Freischaltung** | Ein Modul oder Videokapitel öffnet sich erst eine bestimmte Zahl von Tagen nach Beginn des Zugangs. | Drip, zeitgesteuerte Freigabe |
| **Aussetzen** | Zugang vorübergehend sperren, z. B. bei einem Zahlungskonflikt. Lässt sich wieder aktivieren. | Suspend |
| **Widerrufen** | Zugang endgültig beenden; der Status heißt danach „beendet“. | Revoke |
| **Freigeben** | Inhalte, Regelwerke oder einen Import für die Auslieferung an Lernende zulassen – nach Prüfung der Rechte bzw. der Vorschau. | Release, Approve |
| **Rechte-Status** | Ob Inhalte gezeigt werden dürfen: lizenziert, frei (gemeinfrei), in Prüfung, ungeklärt oder gesperrt. „Ungeklärt“ und „gesperrt“ werden nicht ausgeliefert. | unknown, restricted … |
| **Lokale Abweichung** | Von Hand gesetzter Wert (mit Begründung), der den importierten Wert ersetzt und bei neuen Importen erhalten bleibt. | Override |
| **Regelwerk** | Prüfungsregeln eines Kurses: Zeit, Fragen je Modul, Bestehensgrenze. | Exam Rule |
| **Prüfungsmodus / Übungsmodus** | Mit freigegebenem, geprüftem Regelwerk läuft die Prüfungssimulation im Prüfungsmodus, sonst als Übung. | live, Live-Modus, „nur Übung“ |
| **Inhaltspaket** | Die importierte Sammlung aller Kursinhalte (Fragen, Medien, Aufgaben, Videos, Regelwerke) in einer Version. | Content-Paket, Package, Release |
| **Fassung** | Stand einer einzelnen Frage (neue Fassung nach geändertem Text). | Revision, Rev. |
| **Gruppenlizenz** | Ein Kauf mit mehreren Plätzen; die verwaltende Person lädt Teilnehmende ein. Jeder belegte Platz ist ein Zugang. „Entfernen (Platz wird frei)“ beendet den Zugang einer Person. | Platz freigeben |
| **Protokoll** | Liste aller Änderungen an Zugängen, Gruppen, Inhalten, Importen und Einstellungen – wer, wann, warum (Bootsprüfung › Protokoll). | Audit-Log |
| **App-Kennung** | Kennung der Bootsschule für die App (technisch: Kopfzeile `X-Tenant-Slug`). | Tenant-Slug |

## Regeln für Admin-Texte

- Nur die Begriffe oben; keine englischen Fachwörter, wo es ein deutsches Wort gibt. Produkt- und Markennamen
  (WooCommerce, PayPal, App Store) und technische Werte, die man so eintragen muss (Header-Namen, Konstanten,
  Befehle), bleiben.
- Keine Kapitelverweise („Kap. 7.3“) und keine Pfade ins Repository (`docs/…`) in Texten, die im Admin erscheinen.
  Verweise auf die Dokumentation gehören in die Dokumentation selbst (z. B. `docs/admin-bereich.md`).
- Interne Werte (Status, Rechte, Typen) erscheinen im Admin mit deutscher Bezeichnung (`Admin/Ui::label()`,
  `Commerce/Labels`); der Rohwert steht höchstens im Tooltip.
