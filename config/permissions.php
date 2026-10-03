<?php

return [
    'R01_SUPERADMIN' => ['*'],

    'R09_CAMPO' => [
        'view.gobernador',
        'view.mapa',
        'view.votantes',
        'write.data',
    ],

    // Roles futuros — descomentar y ajustar cuando se activen
    // 'R02_ADMIN_FUNCIONAL' => ['view.gobernador', 'view.mapa', 'view.votantes', 'view.admin', 'manage.users', 'manage.territory', 'manage.partidos', 'write.data'],
    // 'R04_DIRECTIVO'       => ['view.gobernador', 'view.mapa', 'view.votantes'],
    // 'R06_ANALISTA'        => ['view.gobernador', 'view.mapa', 'view.votantes', 'view.admin'],
];
