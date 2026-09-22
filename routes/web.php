<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::view('/login', 'auth')->name('login');
Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
Route::post('/auth/otp/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth');
Route::view('/', 'welcome')->name('home');
Route::middleware(['auth', 'customer'])->get('/portal', fn () => view('dashboard', ['mode' => 'customer']));
Route::middleware(['auth', 'admin'])->get('/admin', fn () => view('dashboard', ['mode' => 'admin']));
