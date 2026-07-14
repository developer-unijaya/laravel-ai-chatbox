<?php

use DeveloperUnijaya\AiChatbox\Http\Controllers\ChatboxController;
use Illuminate\Support\Facades\Route;

Route::get('health', [ChatboxController::class, 'healthCheck'])->name('ai-chatbox.health');
Route::post('message', [ChatboxController::class, 'sendMessage'])->name('ai-chatbox.message');
Route::post('stream', [ChatboxController::class, 'streamMessage'])->name('ai-chatbox.stream');
Route::post('clear', [ChatboxController::class, 'clearHistory'])->name('ai-chatbox.clear');
