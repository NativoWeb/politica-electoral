<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventBarrio extends Model
{
    use HasUuids;

    protected $fillable = ['event_id', 'nombre', 'nombre_normalizado'];
}
