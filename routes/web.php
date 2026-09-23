<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FreeSwitch\XmlController;
use App\Http\Controllers\InboundRouteController;
use App\Http\Controllers\OutboundRouteController;
use App\Http\Controllers\SipExtensionController;
use App\Http\Controllers\SipGatewayController;
use App\Http\Controllers\SipNumberController;
use App\Http\Middleware\AuthenticateFreeSwitch;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    return view('auth', [
        'isAdmin' => str_contains(request()->getHost(), 'admin.'),
    ]);
})->name('login');
Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
Route::post('/auth/otp/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth');
Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'customer:customer'])->group(function () {
    Route::get('/portal', fn () => view('dashboard', ['mode' => 'customer']))->name('portal');
    Route::resource('sip-numbers', SipNumberController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-numbers' => 'sip_number']);
    Route::resource('sip-extensions', SipExtensionController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-extensions' => 'sip_extension']);
    Route::resource('inbound-routes', InboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['inbound-routes' => 'inbound_route']);
    Route::resource('outbound-routes', OutboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['outbound-routes' => 'outbound_route']);
});

Route::middleware(['auth', 'admin:admin'])->group(function () {
    Route::get('/admin', fn () => view('dashboard', ['mode' => 'admin']))->name('admin');
    Route::resource('sip-gateways', SipGatewayController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-gateways' => 'sip_gateway']);
    Route::get('/admin/sip-numbers', [SipNumberController::class, 'index'])->name('admin.sip-numbers.index');
});

Route::post('/internal/freeswitch/xml', XmlController::class)
    ->middleware([AuthenticateFreeSwitch::class, 'throttle:freeswitch-xml'])
    ->name('freeswitch.xml');
