<?php

namespace App\Http\Requests\Empresarios;

use App\Http\Requests\Eventos\ActaSuggestRequest;

// "¿Es esta persona?" for an organization's acta.
class OrganizacionActaSuggestRequest extends ActaSuggestRequest
{
    use AuthorizesOrganizacionActa;
}
