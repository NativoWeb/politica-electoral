<?php

// Business values of the Campañas electorales module (docs/campanas-electorales.md). The on/off switch and beta users
// live in config/features.php.
return [

    // DANE code of the departamento whose municipios a campaign covers.
    'departamento_codigo' => '68',

    // How the goals are suggested: defaults of a new campaign and the range each campaign may use.
    'factores' => [
        'factor_votos' => ['defecto' => 1.10, 'min' => 0.50, 'max' => 3.00],
        'factor_registro' => ['defecto' => 1.00, 'min' => 0.10, 'max' => 3.00],
        'votos_por_lider' => ['defecto' => 50, 'min' => 5, 'max' => 1000],
    ],

    'anio' => ['min' => 2024, 'max' => 2040, 'defecto' => 2027],

    'meta_max' => 10_000_000,

    // Goals saved in one PUT /metas.
    'lote_max' => 300,

    'importacion' => ['max_kb' => 2048, 'max_filas' => 2000],

    'listado_max' => 200,

    'historial_max' => 100,

    'candidatos_max' => 20,
];
