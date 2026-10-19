<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_phone', 20)->nullable()->unique();
            $table->timestamp('whatsapp_verified_at')->nullable();
        });

        Schema::create('whatsapp_link_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('code_hash', 64)->index();
            $table->string('phone_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_link_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['whatsapp_phone']);
            $table->dropColumn(['whatsapp_phone', 'whatsapp_verified_at']);
        });
    }
};
