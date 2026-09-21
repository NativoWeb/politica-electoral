<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMembership extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'person_id', 'organization_id', 'role',
        'valid_from', 'valid_to', 'source_id',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(PoliticalOrganization::class, 'organization_id');
    }
}
