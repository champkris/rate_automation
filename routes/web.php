<?php

use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\RateExtractionController;
use Illuminate\Support\Facades\Route;

// Redirect home to rate extraction
Route::get('/', function () {
    return redirect()->route('rate-extraction.index');
});

// Rate Extraction routes
Route::get('/extract', [RateExtractionController::class, 'index'])->name('rate-extraction.index');
Route::post('/extract', [RateExtractionController::class, 'process'])->name('rate-extraction.process');
Route::get('/extract/result', [RateExtractionController::class, 'result'])->name('rate-extraction.result');
Route::get('/extract/download/{filename}', [RateExtractionController::class, 'download'])->name('rate-extraction.download');

// Google OAuth routes for Gmail API
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('google.auth');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
