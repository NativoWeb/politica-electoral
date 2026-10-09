<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_sends', function (Blueprint $table) {
            $table->string('campaign_type')
                ->nullable()
                ->after('template_id');

            $table->date('campaign_date')
                ->nullable()
                ->after('campaign_type');

            $table->index(
                ['campaign_type', 'campaign_date'],
                'whatsapp_sends_campaign_type_date_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_sends', function (Blueprint $table) {
            $table->dropIndex(
                'whatsapp_sends_campaign_type_date_index'
            );

            $table->dropColumn([
                'campaign_type',
                'campaign_date',
            ]);
        });
    }
};