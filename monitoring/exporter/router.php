<?php
// Router for the exporter's PHP built-in server.
// The whole project is mounted at /app, but only the metrics endpoint is
// exposed on port 9091; every other path returns 404.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/monitoring/metrics.php' || $path === '/metrics') {
    require '/app/monitoring/metrics.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 Not Found\n";
return true;
