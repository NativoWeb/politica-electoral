<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonMergeTransaction extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'survivor_id', 'absorbed_id', 'performed_by',
        'match_score', 'match_evidence', 'before_snapshot',
        'status', 'reason',
    ];

    protected $casts = [
        'match_score' => 'float',
        'match_evidence' => 'array',
        'before_snapshot' => 'array',
    ];

    public function survivor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'survivor_id');
    }

    public function absorbed(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'absorbed_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
