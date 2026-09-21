<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RecentView extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'viewable_type', 'viewable_id', 'viewed_at'];

    protected $casts = ['viewed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function viewable(): MorphTo
    {
        return $this->morphTo();
    }
}
