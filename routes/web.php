<?php

use App\Http\Controllers\Admin\AdminDidController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FreeSwitch\XmlController;
use App\Http\Controllers\InboundRouteController;
use App\Http\Controllers\OutboundRouteController;
use App\Http\Controllers\SipExtensionController;
use App\Http\Middleware\AuthenticateFreeSwitch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$isAdminHost = fn (Request $request): bool => str_contains($request->getHost(), 'admin.');

$loginView = function () use ($isAdminHost) {
    $request = request();

    if (auth()->check()) {
        $user = auth()->user();

        if ($isAdminHost($request)) {
            return $user->isAdmin()
                ? redirect()->route('admin')
                : redirect()->route('login');
        }

        abort_unless($user->isAdmin(), 403);

        return redirect()->route('admin');
    }

    return view('auth', ['isAdmin' => true]);
};

Route::get('/login', $loginView)->name('login');

Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
Route::post('/auth/otp/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth');

Route::get('/', function (Request $request) use ($isAdminHost) {
    if (! $isAdminHost($request)) {
        return view('welcome');
    }

    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return auth()->user()->isAdmin()
        ? redirect()->route('admin')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'admin:admin'])->group(function () {
    Route::get('/admin', [DashboardController::class, 'admin'])->name('admin');
    Route::resource('sip-extensions', SipExtensionController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-extensions' => 'sip_extension']);
    Route::resource('inbound-routes', InboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['inbound-routes' => 'inbound_route']);
    Route::resource('outbound-routes', OutboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['outbound-routes' => 'outbound_route']);

    Route::get('/admin/sip-numbers', [AdminDidController::class, 'index'])->name('admin.sip-numbers.index');
    Route::post('/admin/sip-numbers', [AdminDidController::class, 'store'])->name('admin.sip-numbers.store');
    Route::put('/admin/sip-numbers/{sip_number}', [AdminDidController::class, 'update'])->name('admin.sip-numbers.update');
    Route::delete('/admin/sip-numbers/{sip_number}', [AdminDidController::class, 'destroy'])->name('admin.sip-numbers.destroy');
});

Route::post('/internal/freeswitch/xml', XmlController::class)
    ->middleware([AuthenticateFreeSwitch::class, 'throttle:freeswitch-xml'])
    ->name('freeswitch.xml');
