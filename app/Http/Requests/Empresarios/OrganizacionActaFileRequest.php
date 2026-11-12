<?php

namespace App\Http\Requests\Empresarios;

use App\Http\Requests\Eventos\ActaFileRequest;

// The acta's file, checked against an organization.
class OrganizacionActaFileRequest extends ActaFileRequest
{
    use AuthorizesOrganizacionActa;
}
