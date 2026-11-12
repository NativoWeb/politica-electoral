<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrganizacionImportRow extends Model
{
    use HasUuids;

    protected $table = 'organizacion_import_rows';

    protected $guarded = ['*'];

    protected $casts = ['datos' => 'array', 'errores' => 'array'];
}
