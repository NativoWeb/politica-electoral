<?php

namespace App\Models;

use App\Models\Concerns\SuperadminOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Public entity where a ficha holds a contract (imported from Electo). Superadmin only, like its contracts.
class FuncionarioEntidad extends Model
{
    use HasUuids, SuperadminOnly;

    protected $table = 'funcionario_entidades';

    protected $guarded = ['*'];

    protected $casts = ['is_active' => 'boolean'];

    public function contratos(): HasMany
    {
        return $this->hasMany(FuncionarioContrato::class, 'entidad_id');
    }
}
