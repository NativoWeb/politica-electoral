<?php

namespace App\Console\Commands;

use App\Mail\CompromisosResumen;
use App\Models\User;
use App\Services\Eventos\Actas\CompromisoAlertas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

// Daily e-mail (scheduled 7:00 a. m. Bogotá) to every active user with manage.eventos and a valid e-mail: their
// overdue, due-soon and reminded compromisos, only of their campaigns and territory (CompromisoAlertas runs as that
// user). Nobody with nothing pending gets a message. Logs carry counts and user ids only.
class CompromisosResumenDiario extends Command
{
    protected $signature = 'compromisos:resumen-diario {--dry-run : Muestra a quién se enviaría, sin enviar}';

    protected $description = 'Envía a cada gestor de eventos el resumen de compromisos vencidos, por vencer y con recordatorio';

    public function handle(CompromisoAlertas $alertas): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! self::mailConfigured()) {
            Log::warning('compromisos:resumen-diario: correo no configurado, no se envía nada.');
            $this->warn('Correo no configurado (MAIL_MAILER): no se envía nada.');

            return self::SUCCESS;
        }

        $activeCampaigns = DB::table('campaigns')->where('is_active', true)->pluck('id')->all();
        $users = User::where('is_active', true)->with('role')->get()
            ->filter(fn (User $u) => $u->hasPermission('manage.eventos')
                && filter_var($u->email, FILTER_VALIDATE_EMAIL)
                && in_array($u->campaignId(), $activeCampaigns, true));

        $sent = 0;
        $failed = 0;
        $url = rtrim((string) config('app.url'), '/').'/eventos/compromisos';
        foreach ($users as $user) {
            request()->setUserResolver(fn () => $user);
            $summary = $alertas->summary(50);
            if ($summary['total'] === 0) {
                continue;
            }
            if ($dry) {
                $this->line("Usuario {$user->id}: {$summary['vencidos']} vencidos, {$summary['por_vencer']} por vencer, {$summary['recordatorios']} recordatorios.");
                $sent++;

                continue;
            }
            try {
                Mail::to($user->email)->send(new CompromisosResumen($user->name, $summary, $url));
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('compromisos:resumen-diario: no se pudo enviar', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
        request()->setUserResolver(fn () => null);

        Log::info('compromisos:resumen-diario', ['revisados' => $users->count(), 'enviados' => $sent, 'fallidos' => $failed, 'dry_run' => $dry]);
        $this->info(($dry ? 'Se enviaría a ' : 'Enviados: ')."{$sent} de {$users->count()} usuarios.".($failed ? " Fallidos: {$failed}." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    // log/array only write locally; smtp needs a host.
    public static function mailConfigured(): bool
    {
        $mailer = (string) config('mail.default');
        if ($mailer === '' || in_array($mailer, ['log', 'array'], true)) {
            return false;
        }
        if ((config("mail.mailers.{$mailer}.transport") ?? $mailer) === 'smtp') {
            return trim((string) config("mail.mailers.{$mailer}.host")) !== '';
        }

        return true;
    }
}
