<?php
// Router for `php -S` (used by start-viewer.sh / start-editor.sh).
// Serves / as a landing page so the root URL doesn't 404.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/' || $uri === '/index.php') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>infradex</title></head><body>'
        . '<h1>infradex</h1>'
        . '<ul><li><a href="public/">public/</a> (public-facing infrastructure website)</li>'
        . '<li><a href="viewer.php">viewer.php</a> (internal read-only inventory)</li>'
        . '<li><a href="editor.php">editor.php</a> (edit infra domains, token required)</li>'
        . '<li><a href="dnbx-editor.php">dnbx-editor.php</a> (visual editor for dnbx.de PHP array domains)</li></ul>'
        . '</body></html>';
    return;
}

if ($uri === '/public' || $uri === '/public/') {
    require __DIR__ . '/public/index.php';
    return;
}

$candidate = __DIR__ . $uri;
if (is_file($candidate) && substr($candidate, -4) === '.php') {
    require $candidate;
    return;
}

return false; // let the built-in server handle static files / 404s
