<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppSendRecipient extends Model
{
    protected $table = 'whatsapp_send_recipients';

    protected $fillable = [
        'whatsapp_send_id',
        'person_id',
        'name',
        'phone',
        'status',
    ];

    public function send(): BelongsTo
    {
        return $this->belongsTo(
            WhatsAppSend::class,
            'whatsapp_send_id'
        );
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(
            Person::class,
            'person_id'
        );
    }
}