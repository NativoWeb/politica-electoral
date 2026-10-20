<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Production was altered by hand to allow a null cargo; this aligns fresh databases with it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lideres', function (Blueprint $table) {
            $table->string('cargo')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('lideres')->whereNull('cargo')->update(['cargo' => '']);
        Schema::table('lideres', function (Blueprint $table) {
            $table->string('cargo')->nullable(false)->change();
        });
    }
};
