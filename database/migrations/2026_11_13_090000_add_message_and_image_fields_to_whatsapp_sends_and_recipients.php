
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_sends', function (Blueprint $table) {
            $table->text('image_url')->nullable();
        });

        Schema::table('whatsapp_send_recipients', function (Blueprint $table) {
            $table->text('message_text')->nullable();
            $table->text('image_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_send_recipients', function (Blueprint $table) {
            $table->dropColumn([
                'message_text',
                'image_url',
            ]);
        });

        Schema::table('whatsapp_sends', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
