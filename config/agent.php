<?php

return [

    // Shared secret with the WhatsApp agent service. Empty means every /api/agent request is rejected.
    'hmac_secret' => env('AGENT_HMAC_SECRET', ''),

    // Public WhatsApp number of the assistant (E.164) users write to when linking their phone.
    'whatsapp_number' => env('AGENT_WHATSAPP_NUMBER', ''),

    'max_clock_skew' => 300,

    'nonce_ttl' => 600,

    'token_ttl_minutes' => 15,

    'link_code_ttl_minutes' => 10,

    'link_max_failed_attempts' => 5,

    // Failed /link attempts across all phones within the code window before /link answers 429 to everyone.
    'link_global_max_failures' => 100,

    // GET /votantes/export: rows per file (the first ones by nombre; X-Export-Truncated says when more matched).
    'export_max_rows' => (int) env('AGENT_EXPORT_MAX_ROWS', 1000),

    // Persons WITH contact data (autoriza_datos) the agent may hand to one user per day (America/Bogota), list + export.
    'contact_rows_daily_max' => (int) env('AGENT_CONTACT_ROWS_DAILY_MAX', 500),

    // Requests per minute and user to GET /votantes and /votantes/export, on top of the agent-user limit.
    'votantes_read_per_minute' => (int) env('AGENT_VOTANTES_READ_PER_MINUTE', 20),

];
