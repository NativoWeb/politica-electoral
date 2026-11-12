<?php

namespace App\Http\Requests\Empresarios;

use App\Http\Requests\Eventos\ActaRequest;

// The same body as an event's acta (docs/eventos-api.md §14), checked against an organization.
class OrganizacionActaRequest extends ActaRequest
{
    use AuthorizesOrganizacionActa;
}
