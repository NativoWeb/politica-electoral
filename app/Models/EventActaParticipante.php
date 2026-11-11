<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventActaParticipante extends Model
{
    use HasUuids;

    protected $table = 'event_acta_participantes';

    protected $guarded = ['*'];
}
