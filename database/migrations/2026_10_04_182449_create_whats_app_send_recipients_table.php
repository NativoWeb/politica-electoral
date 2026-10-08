<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_send_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('whatsapp_send_id')
                ->constrained('whatsapp_sends')
                ->cascadeOnDelete();

            $table->uuid('person_id');

            $table->string('name');
            $table->string('phone');

            $table->string('status')
                ->default('pendiente');

            $table->timestamps();

            $table->index('person_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_send_recipients');
    }
};