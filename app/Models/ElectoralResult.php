<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectoralResult extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'contest_id', 'candidacy_id', 'electoral_list_id', 'organization_id',
        'geographic_unit_id', 'grain', 'metric_type', 'value', 'percentage',
        'ranking', 'raw_value', 'source_id', 'status',
    ];

    protected $casts = [
        'value' => 'integer',
        'percentage' => 'decimal:4',
        'ranking' => 'integer',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class);
    }

    public function electoralList(): BelongsTo
    {
        return $this->belongsTo(ElectoralList::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(PoliticalOrganization::class, 'organization_id');
    }

    public function geographicUnit(): BelongsTo
    {
        return $this->belongsTo(GeographicUnit::class);
    }
}
