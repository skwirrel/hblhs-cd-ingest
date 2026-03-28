<?php
/**
 * PHP built-in server router.
 * Start with: php -S localhost:8080 -t public router.php
 *
 * Routing rules:
 *   /api/*          → dispatch to api/ handler scripts (outside doc_root)
 *   real static file → return false (PHP serves from doc_root = public/)
 *   anything else   → serve public/index.php (app shell with injected config)
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API requests
if (strpos($uri, '/api/') === 0) {
    require_once __DIR__ . '/lib/config.php';
    require_once __DIR__ . '/lib/response.php';

    // Log every inbound API request when debug mode is on
    $logCtx = ['method' => $_SERVER['REQUEST_METHOD'], 'uri' => $uri];
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $decoded = json_decode($rawBody, true);
        $logCtx['body'] = is_array($decoded) ? $decoded : $rawBody;
        // Re-expose the body so handlers can still read it
        $GLOBALS['_RAW_INPUT'] = $rawBody;
    }
    debugLog('⬅ request', $logCtx);

    $handlerPath = __DIR__ . $uri . '.php';
    if (file_exists($handlerPath)) {
        require $handlerPath;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'    => false,
        'error' => ['code' => 'NOT_FOUND', 'message' => 'Endpoint not found.'],
    ]);
    return true;
}

// Static files under public/ — let PHP serve them directly
$filePath = __DIR__ . '/public' . $uri;
if ($uri !== '/' && file_exists($filePath) && is_file($filePath)) {
    return false;
}

// Everything else (including /) — serve the app shell
require __DIR__ . '/public/index.php';
return true;
