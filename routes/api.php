<?php

use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\Api\Agent\AgentIdentityController;
use App\Http\Controllers\Api\Agent\AgentVotanteController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::post('/whatsapp/recipients/{recipient}/status', [WhatsAppController::class, 'updateRecipientStatus']);

Route::post('/whatsapp/recipients/status-by-phone', [WhatsAppController::class, 'updateRecipientStatusByPhone']);

Route::get('/whatsapp/birthdays/today', [WhatsAppController::class, 'birthdayRecipients']);

Route::post('/whatsapp/birthdays/recipients/{recipient}/status', [WhatsAppController::class, 'updateBirthdayRecipientStatus']);

// WhatsApp agent: every call is HMAC-signed by the agent service (see VerifyAgentHmac).
Route::prefix('agent/v1')->middleware(['throttle:agent', 'agent.hmac'])->group(function () {
    Route::post('/link', [AgentIdentityController::class, 'link'])->middleware('throttle:agent-link');
    Route::get('/whoami', [AgentIdentityController::class, 'whoami']);
    Route::post('/token', [AgentIdentityController::class, 'token']);

    // Acting-as-user routes: agent token + user still eligible; writes also need agent.can_write.
    Route::middleware(['auth:sanctum', 'abilities:agent', 'agent.user'])->group(function () {
        Route::get('/me', [AgentIdentityController::class, 'me']);

        Route::middleware(['throttle:agent-user', 'audit'])->name('agent.')->whereUuid('id')->group(function () {
            Route::get('/votantes/{id}/seguimientos', [AgentVotanteController::class, 'seguimientos'])->name('votantes.seguimientos.index');
            Route::middleware('throttle:agent-reports')->group(function () {
                Route::get('/reportes/municipio/{id}', [ReportController::class, 'municipio'])->name('reportes.municipio');
                Route::get('/reportes/persona/{id}', [ReportController::class, 'persona'])->name('reportes.persona');
            });

            Route::middleware('agent.can_write')->group(function () {
                Route::post('/votantes', [AgentVotanteController::class, 'store'])->name('votantes.store');
                Route::patch('/votantes/{id}', [AgentVotanteController::class, 'update'])->name('votantes.update');
                Route::post('/votantes/{id}/desactivar', [AgentVotanteController::class, 'desactivar'])->name('votantes.desactivar');
                Route::post('/votantes/{id}/contactos', [AgentVotanteController::class, 'storeContacto'])->name('votantes.contactos.store');
                Route::post('/votantes/{id}/referidos', [AgentVotanteController::class, 'storeReferido'])->name('votantes.referido.store');
                Route::post('/votantes/{id}/nexos', [AgentVotanteController::class, 'storeNexo'])->name('votantes.nexo.store');
                Route::post('/votantes/{id}/seguimientos', [AgentVotanteController::class, 'storeSeguimiento'])->name('votantes.seguimientos.store');
            });
        });
    });
});
