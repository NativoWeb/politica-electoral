<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'action', 'entity_type', 'entity_id',
        'metadata', 'before_state', 'after_state',
        'ip_address', 'user_agent', 'correlation_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'before_state' => 'array',
        'after_state' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
