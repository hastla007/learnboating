<?php
/**
 * Deutsche Wartungsseite (To-do T55). WordPress zeigt sie während Updates statt „Briefly unavailable for scheduled
 * maintenance“. Einsatz: nach wp-content/maintenance.php kopieren (siehe docs/livegang-drehbuch.md).
 * Läuft vor WordPress: keine WordPress-Funktionen verwenden.
 */
if (! headers_sent()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 120');
    header('Cache-Control: no-store');
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Kurze Wartung</title>
<style>
body{margin:0;font:17px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f7fa;color:#10243a;display:flex;min-height:100vh;align-items:center;justify-content:center}
main{max-width:34rem;margin:1rem;padding:2rem;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(16,36,58,.08)}
h1{font-size:1.5rem;margin:0 0 .5rem}
@media (prefers-color-scheme:dark){body{background:#0b1622;color:#e6eef6}main{background:#13222f;box-shadow:none}}
</style>
</head>
<body>
<main>
<h1>Wir sind gleich wieder da</h1>
<p>Die Website wird gerade aktualisiert. Das dauert meist nur ein bis zwei Minuten.</p>
<p>Dein Lernstand ist sicher. Läuft gerade eine Prüfungssimulation, werden deine Antworten danach automatisch nachgesendet. Bitte lade die Seite gleich neu.</p>
</main>
</body>
</html>
<?php
exit;
