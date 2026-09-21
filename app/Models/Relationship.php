<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Relationship extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'person_a_id', 'person_b_id', 'type', 'subtype',
        'is_symmetric', 'direction_label', 'valid_from', 'valid_to',
        'confidence', 'data_classification', 'source_id',
    ];

    protected $casts = [
        'is_symmetric' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function personA(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_a_id');
    }

    public function personB(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_b_id');
    }
}
