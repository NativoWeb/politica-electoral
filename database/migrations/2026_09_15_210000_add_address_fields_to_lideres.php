<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('lideres', function (Blueprint $table) {
            $table->string('direccion')->nullable()->after('observacion');
            $table->string('barrio')->nullable()->after('direccion');
            $table->string('zona')->nullable()->after('barrio'); // rural, urbana
        });
    }
    public function down(): void {
        Schema::table('lideres', function (Blueprint $table) {
            $table->dropColumn(['direccion', 'barrio', 'zona']);
        });
    }
};
