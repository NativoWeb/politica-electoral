<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Append-only proof of a data processing authorization: rows are only ever inserted.
class DataConsent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['accepted_at' => 'datetime'];
}
