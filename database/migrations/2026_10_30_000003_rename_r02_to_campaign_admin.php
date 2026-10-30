<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Multi-campaign step 3: R02 is the "Administrador de campaña" (manages the users of its own campaign).
// Only the visible name and description change; the code R02_ADMIN_FUNCIONAL stays.
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('Administrador de campaña', 'Gestiona los usuarios, el WhatsApp masivo y la auditoría de su campaña');
    }

    public function down(): void
    {
        $this->rename('Administrador funcional', 'Gestiona catálogos, usuarios y configuración de negocio');
    }

    private function rename(string $name, string $description): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::table('roles')->where('code', 'R02_ADMIN_FUNCIONAL')
            ->update(['name' => $name, 'description' => $description, 'updated_at' => now()]);
    }
};
