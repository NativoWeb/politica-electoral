<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectoralList extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'contest_id', 'organization_id', 'list_name', 'list_number', 'modality',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(PoliticalOrganization::class, 'organization_id');
    }

    public function candidacies(): HasMany
    {
        return $this->hasMany(Candidacy::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ElectoralResult::class);
    }
}
