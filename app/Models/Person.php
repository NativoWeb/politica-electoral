<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Person extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'persons';

    protected $fillable = [
        'first_name', 'last_name', 'full_name', 'normalized_name',
        'photo_path', 'gender', 'birth_date', 'document_type',
        'doc_hash', 'doc_last4', 'identity_status', 'merged_into_id',
        'data_classification',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'merged_into_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(PersonAlias::class);
    }

    public function contactPoints(): HasMany
    {
        return $this->hasMany(PersonContactPoint::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(PersonFact::class);
    }

    public function candidacies(): HasMany
    {
        return $this->hasMany(Candidacy::class);
    }

    public function officeTenures(): HasMany
    {
        return $this->hasMany(OfficeTenure::class);
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function relationshipsAsA(): HasMany
    {
        return $this->hasMany(Relationship::class, 'person_a_id');
    }

    public function relationshipsAsB(): HasMany
    {
        return $this->hasMany(Relationship::class, 'person_b_id');
    }
}
