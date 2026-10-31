<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// campaign_id null = a type every campaign can use.
class EventType extends Model
{
    use HasUuids;

    protected $fillable = ['nombre', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
