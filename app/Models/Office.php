<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'type', 'is_uninominal'];

    protected $casts = ['is_uninominal' => 'boolean'];

    public function contests(): HasMany
    {
        return $this->hasMany(Contest::class);
    }

    public function tenures(): HasMany
    {
        return $this->hasMany(OfficeTenure::class);
    }
}
