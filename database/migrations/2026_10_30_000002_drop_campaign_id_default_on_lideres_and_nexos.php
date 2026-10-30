<?php

use App\Models\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Step 2 of multi-campaign: every insert into lideres and nexos_familiares now sets campaign_id, so the
// default is dropped and an insert that forgets the campaign fails instead of landing in Centro Democrático.
// users and whatsapp_* keep the default until step 3. Raw SQL on purpose: the agent_ro views depend on
// lideres and production was altered by hand (see 2026_10_30_000001). SQLite cannot alter a default.
return new class extends Migration
{
    private const TABLES = ['lideres', 'nexos_familiares'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN campaign_id DROP DEFAULT");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $default = Campaign::DEFAULT_ID;
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN campaign_id SET DEFAULT '{$default}'");
        }
    }
};
