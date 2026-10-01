<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lider extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'lideres';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        // Original fields
        'nombre', 'provincia', 'municipio', 'cargo', 'tipo',
        'telefono', 'cedula', 'email', 'observacion', 'geographic_unit_id',
        'partido', 'direccion', 'barrio', 'zona', 'destacado', 'profesion', 'votos',
        // Identidad
        'tipo_documento', 'fecha_expedicion_doc', 'genero', 'fecha_nacimiento', 'foto',
        // Personal
        'estado_civil',
        // Referente
        'referente_documento', 'referente_nombre', 'referente_apellido',
        // Puesto de votacion
        'departamento_votacion', 'municipio_votacion', 'puesto_votacion',
        'direccion_puesto', 'mesa_votacion',
        // Tags booleanos
        'militante', 'autoriza_datos', 'verificado', 'fallecido',
        'empresario', 'reservista', 'funcionario', 'exfuncionario', 'gran_elector',
        // Confianza y convenio
        'nivel_confianza', 'convenio',
        // Hoja de vida
        'escolaridad', 'tipo_hoja_vida', 'fecha_registro_hv',
        // Redes sociales
        'facebook', 'twitter', 'instagram',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'fecha_expedicion_doc' => 'date',
        'fecha_registro_hv' => 'date',
        'destacado' => 'boolean',
        'militante' => 'boolean',
        'autoriza_datos' => 'boolean',
        'verificado' => 'boolean',
        'fallecido' => 'boolean',
        'empresario' => 'boolean',
        'reservista' => 'boolean',
        'funcionario' => 'boolean',
        'exfuncionario' => 'boolean',
        'gran_elector' => 'boolean',
        'votos' => 'integer',
    ];

    public function contactos(): HasMany
    {
        return $this->hasMany(LiderContacto::class);
    }

    public function infoPolitica(): HasMany
    {
        return $this->hasMany(LiderInfoPolitica::class);
    }

    public function nexosFamiliares(): HasMany
    {
        return $this->hasMany(NexoFamiliar::class, 'person_id');
    }

    public function geographicUnit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class);
    }
}
