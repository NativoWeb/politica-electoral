<?php

namespace App\Models\Concerns;

use App\Support\CurrentCampaign;
use Illuminate\Database\Eloquent\Builder;

// Rows only an authenticated superadmin can read. Without an authenticated user (artisan commands) nothing is
// filtered, as with the campaign scope.
trait SuperadminOnly
{
    protected static function bootSuperadminOnly(): void
    {
        static::addGlobalScope('superadmin_only', function (Builder $query) {
            $current = app(CurrentCampaign::class);
            if ($current->restricted() && ! $current->isSuperadmin()) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
