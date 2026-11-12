<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Staging of an Excel load of organizations: rows are reviewed and corrected before anything is written.
class OrganizacionImport extends Model
{
    use HasUuids;

    protected $table = 'organizacion_imports';

    protected $guarded = ['*'];

    protected $casts = ['resumen' => 'array', 'confirmed_at' => 'datetime'];

    public function rows(): HasMany
    {
        return $this->hasMany(OrganizacionImportRow::class, 'import_id')->orderBy('row_number');
    }
}
