<?php

namespace App\Models;

use Database\Factories\ElectoralCampaignFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A contest a grupo político prepares (docs/campanas-electorales.md). Not the grupo político itself (Campaign):
// campaign_id is the owner group, set by the server together with status, archived_at and the authors.
class ElectoralCampaign extends Model
{
    /** @use HasFactory<ElectoralCampaignFactory> */
    use HasFactory, HasUuids;

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_MUNICIPALITY = 'municipality';

    public const SCOPE_COMUNA = 'comuna';

    // cargo => label and the scopes it may use (the first one is the default).
    public const CARGOS = [
        'gobernacion' => ['label' => 'Gobernación', 'scopes' => [self::SCOPE_DEPARTMENT]],
        'alcaldia' => ['label' => 'Alcaldía', 'scopes' => [self::SCOPE_MUNICIPALITY]],
        'concejo' => ['label' => 'Concejo', 'scopes' => [self::SCOPE_MUNICIPALITY]],
        'asamblea' => ['label' => 'Asamblea', 'scopes' => [self::SCOPE_DEPARTMENT]],
        'senado' => ['label' => 'Senado', 'scopes' => [self::SCOPE_DEPARTMENT, self::SCOPE_MUNICIPALITY]],
        'camara' => ['label' => 'Cámara', 'scopes' => [self::SCOPE_DEPARTMENT, self::SCOPE_MUNICIPALITY]],
        'jal' => ['label' => 'JAL', 'scopes' => [self::SCOPE_COMUNA]],
    ];

    // Cargos whose contest is held in exactly one municipio.
    public const UN_MUNICIPIO = ['alcaldia', 'concejo'];

    protected $fillable = [
        'name', 'cargo', 'election_year', 'election_date', 'candidate_person_id', 'candidate_name',
        'political_organization_id', 'scope_level', 'factor_votos', 'factor_registro', 'votos_por_lider',
    ];

    protected $casts = [
        'election_year' => 'integer',
        'election_date' => 'date:Y-m-d',
        'factor_votos' => 'float',
        'factor_registro' => 'float',
        'votos_por_lider' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function cargoLabel(): string
    {
        return self::CARGOS[$this->cargo]['label'] ?? $this->cargo;
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'candidate_person_id');
    }

    public function politicalOrganization(): BelongsTo
    {
        return $this->belongsTo(PoliticalOrganization::class, 'political_organization_id');
    }

    public function territories(): HasMany
    {
        return $this->hasMany(ElectoralCampaignTerritory::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(ElectoralCampaignGoal::class);
    }
}
