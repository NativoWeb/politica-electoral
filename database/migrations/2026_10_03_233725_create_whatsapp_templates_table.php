<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('meta_name')->unique();
            $table->text('body_text');

            $table->json('variables')->nullable();

            $table->string('header_type')->nullable();
            $table->string('header_text')->nullable();

            $table->string('language', 20)->default('es');
            $table->string('category', 50)->nullable();

            $table->boolean('is_active')->default(true);

            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};