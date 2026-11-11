<?php

use App\Http\Controllers\Customer\AudioCallController;
use App\Http\Middleware\AuthenticateAudioCallKey;
use Illuminate\Support\Facades\Route;

Route::domain(config('portal.customer_domain'))->prefix('v1')->middleware('throttle:90,1')->group(function () {
    Route::middleware(AuthenticateAudioCallKey::class)->group(function () {
        Route::post('/audio-calls', [AudioCallController::class, 'store']);
        Route::get('/audio-calls/{call}', [AudioCallController::class, 'show'])->whereUuid('call')->name('api.audio-calls.show');
        Route::post('/audio-assets', [AudioCallController::class, 'upload'])->middleware('throttle:10,1');
    });
});
