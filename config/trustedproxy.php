<?php

return [

    // Comma-separated IPs/CIDRs of reverse proxies allowed to set X-Forwarded-*. Empty = trust none,
    // which is right for nginx + php-fpm on the same host (REMOTE_ADDR is already the client).
    // Only list the proxy in front of nginx (load balancer, Cloudflare ranges) if there is one.
    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
