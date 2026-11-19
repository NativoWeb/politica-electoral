<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppDailyAnalytic extends Model
{
    protected $table = 'whatsapp_daily_analytics';

    protected $fillable = [
        'waba_id',
        'date',
        'sent',
        'delivered',
        'volume',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sent' => 'integer',
            'delivered' => 'integer',
            'volume' => 'integer',
            'cost' => 'decimal:6',
        ];
    }
}
