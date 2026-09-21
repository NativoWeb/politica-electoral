<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoalitionMember extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'coalition_id', 'organization_id', 'role', 'joined_at', 'left_at',
    ];

    protected $casts = [
        'joined_at' => 'date',
        'left_at' => 'date',
    ];

    public function coalition(): BelongsTo
    {
        return $this->belongsTo(Coalition::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(PoliticalOrganization::class, 'organization_id');
    }
}
