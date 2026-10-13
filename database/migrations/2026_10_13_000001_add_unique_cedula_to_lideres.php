<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX lideres_cedula_unique ON lideres(cedula) WHERE cedula IS NOT NULL AND cedula != ''");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS lideres_cedula_unique');
    }
};
