<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Candidacy extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'person_id', 'contest_id', 'electoral_list_id', 'list_position',
        'outcome', 'outcome_source', 'outcome_date',
    ];

    protected $casts = [
        'list_position' => 'integer',
        'outcome_date' => 'date',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function electoralList(): BelongsTo
    {
        return $this->belongsTo(ElectoralList::class);
    }

    public function endorsements(): HasMany
    {
        return $this->hasMany(CandidacyEndorsement::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ElectoralResult::class);
    }

    public function officeTenure(): HasMany
    {
        return $this->hasMany(OfficeTenure::class);
    }
}
