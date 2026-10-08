<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppSend extends Model
{
    protected $table = 'whatsapp_sends';

    protected $fillable = [
        'template_id',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(
            WhatsAppTemplate::class,
            'template_id'
        );
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(
            WhatsAppSendRecipient::class,
            'whatsapp_send_id'
        );
    }
}