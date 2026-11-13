<?php

// Modules that stay hidden until they are switched on. A module that is off answers 404 to everyone, the
// superadmin included, and its permissions are not offered in the users screen.
return [

    // Electoral campaigns (Gobernación, Alcaldía... contests). Not the tenant "grupo político" (campaigns table).
    'campanas_electorales' => [
        'enabled' => (bool) env('CAMPANAS_ELECTORALES_ENABLED', false),

        // User ids (comma separated) that see the module while it is in beta. Empty = everyone with its permission.
        'beta_users' => array_values(array_filter(array_map('trim', explode(',', (string) env('CAMPANAS_ELECTORALES_BETA_USERS', ''))))),
    ],

];
