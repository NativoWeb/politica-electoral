<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    // Polymorphic type values that mean "a persons row" (favorites, recent_views, taggables, documents, activities,
    // source_records). There is no morph map and no writer yet; these are the spellings accepted as a person.
    public const MORPH_TYPES = ['person', 'persona', 'persons', self::class];

    // A merged person's id (old links, offline queues, agent calls) stands for its survivor. Any other id, including
    // a lider's, comes back unchanged.
    public static function resolveId(string $id): string
    {
        $seen = [];
        while (Str::isUuid($id) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $next = DB::table('persons')->where('id', $id)->value('merged_into_id');
            if (! $next) {
                break;
            }
            $id = $next;
        }

        return $id;
    }

    // Importers' lookup: a live person with that name, else a merged person with that name followed to its survivor.
    // Aliases are not used: short aliases ("JUAN TORRES") would glue a namesake from another election to a candidate.
    public static function resolveIdByNormalizedName(string $normalized): ?string
    {
        $id = DB::table('persons')->where('normalized_name', $normalized)->whereNull('deleted_at')->whereNull('merged_into_id')
            ->orderBy('created_at')->value('id');
        if ($id) {
            return $id;
        }

        $merged = DB::table('persons')->where('normalized_name', $normalized)->whereNotNull('merged_into_id')->value('id');
        if (! $merged) {
            return null;
        }
        $survivor = self::resolveId($merged);

        return DB::table('persons')->where('id', $survivor)->whereNull('deleted_at')->whereNull('merged_into_id')->exists() ? $survivor : null;
    }

    public static function findLiveByNormalizedName(string $normalized): ?self
    {
        $id = self::resolveIdByNormalizedName($normalized);

        return $id ? self::find($id) : null;
    }

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
