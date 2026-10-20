<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Production was altered by hand to allow a null cargo; this aligns fresh databases with it.
// On PostgreSQL only the constraint is dropped: change() also rewrites the type, which the agent_ro views forbid.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE lideres ALTER COLUMN cargo DROP NOT NULL');

            return;
        }

        Schema::table('lideres', function (Blueprint $table) {
            $table->string('cargo')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('lideres')->whereNull('cargo')->update(['cargo' => '']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE lideres ALTER COLUMN cargo SET NOT NULL');

            return;
        }

        Schema::table('lideres', function (Blueprint $table) {
            $table->string('cargo')->nullable(false)->change();
        });
    }
};
