<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Corporation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'scope', 'default_seats'];

    protected $casts = ['default_seats' => 'integer'];

    public function contests(): HasMany
    {
        return $this->hasMany(Contest::class);
    }
}
