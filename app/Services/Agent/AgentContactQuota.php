<?php

namespace App\Services\Agent;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

// Daily quota (America/Bogota) of persons WITH contact data the agent hands to one user (config agent.contact_rows_daily_max).
// increment() is atomic on the database and redis stores; going over is undone, so concurrent requests never exceed it.
class AgentContactQuota
{
    private const TZ = 'America/Bogota';

    public function max(): int
    {
        return max(0, (int) config('agent.contact_rows_daily_max', 500));
    }

    public function used(string $userId): int
    {
        return (int) Cache::get($this->key($userId), 0);
    }

    public function remaining(string $userId): int
    {
        return max(0, $this->max() - $this->used($userId));
    }

    // Reserves $rows for today; false (and nothing reserved) when it would go over the daily max.
    public function consume(string $userId, int $rows): bool
    {
        if ($rows <= 0) {
            return true;
        }
        if ($rows > $this->max()) {
            return false;
        }

        $key = $this->key($userId);
        Cache::add($key, 0, Carbon::now(self::TZ)->endOfDay()->addHour());
        $total = (int) Cache::increment($key, $rows);
        if ($total > $this->max()) {
            Cache::decrement($key, $rows);

            return false;
        }

        return true;
    }

    // Gives back rows reserved by consume() for a delivery that did not happen.
    public function release(string $userId, int $rows): void
    {
        if ($rows > 0) {
            Cache::decrement($this->key($userId), $rows);
        }
    }

    private function key(string $userId): string
    {
        return 'agent-contact-rows|'.$userId.'|'.Carbon::now(self::TZ)->toDateString();
    }
}
