<?php

use App\Http\Controllers\GmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GmailController::class, 'home'])->name('gmail.home');

Route::get('/gmail/connect', [GmailController::class, 'redirect'])->name('gmail.connect');
Route::get('/gmail/callback', [GmailController::class, 'callback'])->name('gmail.callback');
Route::get('/gmail/inbox', [GmailController::class, 'inbox'])->name('gmail.inbox');
Route::get('/gmail/message/{id}', [GmailController::class, 'show'])->name('gmail.show');
Route::get('/gmail/message/{id}/attachment/{attachmentId}', [GmailController::class, 'attachment'])->name('gmail.attachment');

Route::post('/gmail/compose', [GmailController::class, 'compose'])->name('gmail.compose');
Route::post('/gmail/keywords', [GmailController::class, 'keywords'])->name('gmail.keywords');
Route::post('/gmail/message/{id}/reply', [GmailController::class, 'reply'])->name('gmail.reply');
Route::post('/gmail/message/{id}/forward', [GmailController::class, 'forward'])->name('gmail.forward');
Route::post('/gmail/message/{id}/star', [GmailController::class, 'toggleStar'])->name('gmail.star');
Route::post('/gmail/message/{id}/read', [GmailController::class, 'toggleRead'])->name('gmail.read');
Route::post('/gmail/message/{id}/archive', [GmailController::class, 'archive'])->name('gmail.archive');
Route::post('/gmail/message/{id}/trash', [GmailController::class, 'trash'])->name('gmail.trash');
Route::post('/gmail/message/{id}/untrash', [GmailController::class, 'untrash'])->name('gmail.untrash');
Route::post('/gmail/disconnect', [GmailController::class, 'disconnect'])->name('gmail.disconnect');
