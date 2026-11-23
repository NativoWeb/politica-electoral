<?php

namespace App\Http\Requests\Empresarios;

use App\Http\Requests\Eventos\ActaCorreccionRequest;

// The correction of a confirmed acta, checked against an organization.
class OrganizacionActaCorreccionRequest extends ActaCorreccionRequest
{
    use AuthorizesOrganizacionActa;
}
