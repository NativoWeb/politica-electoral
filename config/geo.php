<?php

// Geography settings (docs/geografia.md).
return [

    // Electo barrio codes that mean "no barrio" (code 1404 holds a third of the Bucaramanga fichas): data:electo-barrios
    // skips them unless the catalog's name for the code is a real barrio of the ficha's municipio.
    'electo_barrio_codigos_sin_barrio' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('GEO_ELECTO_BARRIO_SIN_BARRIO', '1404'))))),

];
