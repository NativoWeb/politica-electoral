<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidacyEndorsement extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'candidacy_id', 'organization_id', 'coalition_id',
        'endorsement_type', 'is_primary', 'source_id',
    ];

    protected $casts = ['is_primary' => 'boolean'];

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(PoliticalOrganization::class, 'organization_id');
    }

    public function coalition(): BelongsTo
    {
        return $this->belongsTo(Coalition::class);
    }
}
