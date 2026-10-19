<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppLinkCode extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_link_codes';

    protected $fillable = ['user_id', 'code_hash', 'expires_at', 'used_at', 'attempts'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    // Keyed hash so the stored value is useless without APP_KEY, and still searchable by equality.
    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', 'whatsapp-link:'.$code, (string) config('app.key'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
