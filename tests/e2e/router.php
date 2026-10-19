<?php

// Router for `php -S` used by pwa-session.e2e.cjs. While "<SW_OVERRIDE_FILE>.active" exists, /sw.js is
// served from SW_OVERRIDE_FILE (an older service worker) without touching public/sw.js.
$override = getenv('SW_OVERRIDE_FILE');
if ($override && parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/sw.js' && is_file($override.'.active')) {
    header('Content-Type: application/javascript');
    header('Cache-Control: no-cache');
    readfile($override);

    return true;
}

return require __DIR__.'/../../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
