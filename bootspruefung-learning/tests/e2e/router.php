<?php
// Router für den PHP-Entwicklungsserver (php -S … tests/e2e/router.php aus dem WordPress-Ordner): echte Dateien direkt, alles andere über index.php (Permalinks).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__.$path;
if ($path !== '/' && is_file($file)) { return false; }
if (is_dir($file) && is_file(rtrim($file, '/').'/index.php')) { $_SERVER['SCRIPT_NAME'] = rtrim($path, '/').'/index.php'; chdir($file); require rtrim($file, '/').'/index.php'; return; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__.'/index.php';
