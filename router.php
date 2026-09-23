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
        . '<ul><li><a href="viewer.php">viewer.php</a> (read-only inventory)</li>'
        . '<li><a href="editor.php">editor.php</a> (edit domains, token required)</li></ul>'
        . '</body></html>';
    return;
}

$candidate = __DIR__ . $uri;
if (is_file($candidate) && substr($candidate, -4) === '.php') {
    require $candidate;
    return;
}

return false; // let the built-in server handle static files / 404s
