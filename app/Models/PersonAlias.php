<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonAlias extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'person_id', 'alias_name', 'normalized_alias', 'alias_type', 'source_id',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
