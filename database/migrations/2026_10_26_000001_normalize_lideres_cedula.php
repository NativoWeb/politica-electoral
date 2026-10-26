<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stores cédulas as digits only ("63.525.141" -> "63525141"), matching App\Support\Cedula.
// A row whose digits already belong to another lider is left as is: that is a duplicate person to merge by hand.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $digits = "regexp_replace(trim(l.cedula), '[[:space:].,''´’‘`-]', '', 'g')";
        DB::statement("
            UPDATE lideres l SET cedula = {$digits}
            WHERE l.cedula IS NOT NULL
              AND {$digits} ~ '^[0-9]+$'
              AND {$digits} <> l.cedula
              AND (SELECT count(*) FROM lideres s WHERE regexp_replace(trim(s.cedula), '[[:space:].,''´’‘`-]', '', 'g') = {$digits}) = 1
        ");

        $refDigits = "regexp_replace(trim(referente_documento), '[[:space:].,''´’‘`-]', '', 'g')";
        DB::statement("
            UPDATE lideres SET referente_documento = {$refDigits}
            WHERE referente_documento IS NOT NULL
              AND {$refDigits} ~ '^[0-9]+$'
              AND {$refDigits} <> referente_documento
        ");
    }

    public function down(): void
    {
        // Formatting is not recoverable and the digits-only value is equivalent.
    }
};
