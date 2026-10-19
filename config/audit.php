<?php

/*
 * Masking of personal data written to audit_logs (Ley 1581 de 2012).
 *
 * Applied to metadata, before_state and after_state on every AuditLog insert, at any nesting
 * level (including lists such as contactos[]). Keys are matched case-insensitively with
 * Str::is patterns, so '*' is a wildcard. The key itself is kept, so the log still shows WHICH
 * field was sent; only the value is reduced. Null values stay null.
 *
 *  - last4: the key becomes "<key>_last4" with the last 4 digits of the value (null if it has none).
 *  - email: "p***@dominio.com" (first character of the local part + domain).
 *  - omit:  the value becomes "[omitido]".
 *  - by_type: contact values whose kind lives in a sibling key (person contacts send {type, value}):
 *    a phone-like type gives "<key>_last4", an email type the email mask, any other type "[omitido]".
 *    Without a type/tipo sibling the value is kept (e.g. vote counts of /admin/elecciones/results).
 *
 * Rows written before this masking existed are fixed once with `php artisan audit:mask-history`
 * (see app/Console/Commands/MaskAuditHistory.php).
 */
return [

    'mask' => [

        'last4' => [
            'cedula',
            'documento',
            'numero_documento',
            'referente_documento',
            'telefono',
            'phone',
            'celular',
            'whatsapp',
            'whatsapp_phone',
            'whatsapp_number',
        ],

        'email' => [
            'email',
        ],

        'omit' => [
            'direccion',
            'direccion_puesto',
            'barrio',
            'fecha_nacimiento',
            'birth_date',
            'fecha_expedicion_doc',
            'edad',
            'gustos',
            'observacion',
            'observaciones',
            'nota',
            'valor',
            '*password*',
            // Political affiliation is sensitive data (Ley 1581, art. 5).
            'partido',
            'partido_id',
            'partido_politico',
            'afiliacion',
            'afiliacion_politica',
            'militante',
            'militancia',
        ],

        'by_type' => [
            'keys' => ['value'],
            'type_keys' => ['type', 'tipo'],
            'last4_types' => ['phone', 'telefono', 'celular', 'movil', 'fijo', 'whatsapp'],
            'email_types' => ['email', 'correo'],
        ],

    ],

    'omitted_placeholder' => '[omitido]',

];
