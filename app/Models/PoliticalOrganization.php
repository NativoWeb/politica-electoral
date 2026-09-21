<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoliticalOrganization extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'type', 'canonical_name', 'acronym', 'color_hex', 'logo_path',
        'status', 'valid_from', 'valid_to', 'merged_into_id',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(PoliticalOrganization::class, 'merged_into_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(OrganizationAlias::class, 'organization_id');
    }

    public function coalitionMemberships(): HasMany
    {
        return $this->hasMany(CoalitionMember::class, 'organization_id');
    }

    public function endorsements(): HasMany
    {
        return $this->hasMany(CandidacyEndorsement::class, 'organization_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ElectoralResult::class, 'organization_id');
    }

    public function lists(): HasMany
    {
        return $this->hasMany(ElectoralList::class, 'organization_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class, 'organization_id');
    }
}
