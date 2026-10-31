<?php

use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\Api\Agent\AgentIdentityController;
use App\Http\Controllers\Api\Agent\AgentVotanteController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('whatsapp')->name('api.whatsapp.')->group(function () {
    Route::post('/recipients/{recipient}/status', [WhatsAppController::class, 'updateRecipientStatus'])->name('recipients.status');

    Route::post('/recipients/status-by-phone', [WhatsAppController::class, 'updateRecipientStatusByPhone'])->name('recipients.status-by-phone');

    Route::get('/birthdays/today', [WhatsAppController::class, 'birthdayRecipients'])->name('birthdays.today');

    Route::post('/birthdays/recipients/{recipient}/status', [WhatsAppController::class, 'updateBirthdayRecipientStatus'])->name('birthdays.recipients.status');
});

// WhatsApp agent: every call is HMAC-signed by the agent service (see VerifyAgentHmac).
Route::prefix('agent/v1')->name('api.agent.')->middleware(['throttle:agent', 'agent.hmac'])->group(function () {
    Route::post('/link', [AgentIdentityController::class, 'link'])->middleware('throttle:agent-link')->name('link');
    Route::get('/whoami', [AgentIdentityController::class, 'whoami'])->name('whoami');
    Route::post('/token', [AgentIdentityController::class, 'token'])->name('token');

    // Acting-as-user routes: agent token + user still eligible; writes also need agent.can_write.
    Route::middleware(['auth:sanctum', 'abilities:agent', 'agent.user'])->group(function () {
        Route::get('/me', [AgentIdentityController::class, 'me'])->name('me');

        Route::middleware(['throttle:agent-user', 'audit'])->whereUuid('id')->group(function () {
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
