<?php
/**
 * Deutsche Fehlerseite bei einem schweren PHP-Fehler (To-do T55). WordPress zeigt sie statt „There has been a critical
 * error on this website“. Einsatz: nach wp-content/php-error.php kopieren (siehe docs/livegang-drehbuch.md).
 * Keine Fehlerdetails ausgeben; die stehen im Server-Log bzw. in der Mail an die Admin-Adresse (Wiederherstellungsmodus).
 */
if (! headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Technischer Fehler</title>
<style>
body{margin:0;font:17px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f7fa;color:#10243a;display:flex;min-height:100vh;align-items:center;justify-content:center}
main{max-width:34rem;margin:1rem;padding:2rem;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(16,36,58,.08)}
h1{font-size:1.5rem;margin:0 0 .5rem}
a{color:#0b5cad}
@media (prefers-color-scheme:dark){body{background:#0b1622;color:#e6eef6}main{background:#13222f;box-shadow:none}a{color:#7cc4ff}}
</style>
</head>
<body>
<main>
<h1>Hier ist etwas schiefgelaufen</h1>
<p>Diese Seite konnte wegen eines technischen Fehlers nicht geladen werden. Wir wurden automatisch benachrichtigt.</p>
<p>Bitte versuche es in ein paar Minuten noch einmal. Dein Lernstand und deine Bestellungen sind davon nicht betroffen.</p>
<p><a href="/">Zur Startseite</a></p>
</main>
</body>
</html>
