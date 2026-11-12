<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Editable catalogs of Empresarios: sector económico and sector. campaign_id null = shared starting values.
class OrgCatalogo extends Model
{
    use HasUuids;

    public const TIPOS = ['sector_economico' => 'Sector económico', 'sector' => 'Sector'];

    protected $table = 'org_catalogos';

    protected $guarded = ['*'];

    protected $casts = ['is_active' => 'boolean'];
}
