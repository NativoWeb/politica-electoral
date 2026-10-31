<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventFigure extends Model
{
    use HasUuids;

    protected $fillable = ['event_id', 'person_id', 'nombre', 'cargo'];
}
