<?php

use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\Api\Agent\AgentIdentityController;
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
    Route::get('/me', [AgentIdentityController::class, 'me'])->middleware(['auth:sanctum', 'abilities:agent']);
});
