<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonContactPoint extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'person_id', 'type', 'value_raw', 'value_normalized', 'label',
        'is_current', 'valid_from', 'valid_to', 'last_verified_at',
        'data_classification', 'allow_export', 'allow_offline', 'source_id',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'allow_export' => 'boolean',
        'allow_offline' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'last_verified_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
