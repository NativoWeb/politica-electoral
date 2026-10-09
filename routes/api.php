<?php

use App\Http\Controllers\Admin\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::post('/whatsapp/recipients/{recipient}/status', [WhatsAppController::class, 'updateRecipientStatus']);

Route::get('/whatsapp/sends/{send}/status', [WhatsAppController::class, 'status']);

Route::get('/whatsapp/birthdays/today', [WhatsAppController::class, 'birthdayRecipients']);

Route::post('/whatsapp/birthdays/recipients/{recipient}/status', [WhatsAppController::class, 'updateBirthdayRecipientStatus']);