<?php

use App\Models\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// Whether a grupo político sees the shared electoral results (candidates, votes and parties of Alcaldía, Concejo,
// Senado, Cámara, Asamblea, Gobernación). Off for every group but the root one (Centro Democrático), which keeps
// seeing them as before; the superadmin always sees them (CurrentCampaign::seesElectoralResults). Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('campaigns', 've_resultados_electorales')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->boolean('ve_resultados_electorales')->default(false);
            });
        }

        $updated = DB::table('campaigns')->where('id', Campaign::DEFAULT_ID)->update(['ve_resultados_electorales' => true]);
        // Without the root group nobody but the superadmin would see the results: say so instead of failing silently.
        if ($updated === 0 && DB::table('campaigns')->exists()) {
            $message = 've_resultados_electorales: no existe el grupo principal '.Campaign::DEFAULT_ID.'; ningún grupo quedó viendo los resultados electorales.';
            Log::warning($message);
            if (app()->runningInConsole()) {
                fwrite(STDERR, "AVISO: {$message}".PHP_EOL);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('campaigns', 've_resultados_electorales')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->dropColumn('ve_resultados_electorales');
            });
        }
    }
};
