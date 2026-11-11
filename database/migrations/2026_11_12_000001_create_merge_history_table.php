<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Every merge made from the ficha ("Unificar con otra persona") with both records as they were before, so a later
// "Separar" can undo it. Holds personal data: never exposed outside the platform, unlike audit_logs (ids only).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merge_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 20)->comment('lideres|vinculo|personas');
            $table->uuid('kept_id')->comment('Record that remains (lider or person)');
            $table->uuid('absorbed_id')->comment('Record merged into it, or the linked person');
            $table->uuid('campaign_id')->nullable()->comment('Campaign of the lideres involved; null for persons only');
            $table->uuid('user_id')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->index('kept_id');
            $table->index('absorbed_id');
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merge_history');
    }
};
