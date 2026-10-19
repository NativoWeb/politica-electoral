<?php

return [

    // Shared secret with the WhatsApp agent service. Empty means every /api/agent request is rejected.
    'hmac_secret' => env('AGENT_HMAC_SECRET', ''),

    'max_clock_skew' => 300,

    'nonce_ttl' => 600,

    'token_ttl_minutes' => 15,

    'link_code_ttl_minutes' => 10,

    'link_max_failed_attempts' => 5,

    // Failed /link attempts across all phones within the code window before /link answers 429 to everyone.
    'link_global_max_failures' => 100,

];
