<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonFact extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'person_id', 'category', 'title', 'description',
        'valid_from', 'valid_to', 'date_precision',
        'confidence', 'data_classification', 'source_id',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
