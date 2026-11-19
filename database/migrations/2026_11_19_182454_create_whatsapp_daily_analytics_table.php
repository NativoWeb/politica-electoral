
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_daily_analytics', function (Blueprint $table) {
            $table->id();

            $table->string('waba_id', 32);

            $table->date('date');

            $table->unsignedInteger('sent')->default(0);

            $table->unsignedInteger('delivered')->default(0);

            $table->unsignedInteger('volume')->default(0);

            $table->decimal('cost', 14, 6)->default(0);

            $table->timestamps();

            $table->unique(
                ['waba_id', 'date'],
                'whatsapp_daily_analytics_waba_date_unique'
            );

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_daily_analytics');
    }
};