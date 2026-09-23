<?php

use App\Http\Controllers\Admin\AdminSipNumberController;
use App\Http\Controllers\Admin\AdminTenantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FreeSwitch\XmlController;
use App\Http\Controllers\InboundRouteController;
use App\Http\Controllers\OutboundRouteController;
use App\Http\Controllers\SipExtensionController;
use App\Http\Controllers\SipGatewayController;
use App\Http\Controllers\SipNumberController;
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

        return $user->isAdmin()
            ? redirect()->route('admin')
            : redirect()->route('portal');
    }

    return view('auth', ['isAdmin' => $isAdminHost($request)]);
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

Route::middleware(['auth', 'customer:customer'])->group(function () {
    Route::get('/portal', [DashboardController::class, 'customer'])->name('portal');

    Route::get('/sip-numbers', [SipNumberController::class, 'index'])->name('sip-numbers.index');
    Route::post('/sip-numbers', [SipNumberController::class, 'store'])->name('sip-numbers.store');
    Route::put('/sip-numbers/{sip_number}', [SipNumberController::class, 'update'])->name('sip-numbers.update');
    Route::post('/sip-numbers/{sip_number}/assign', [SipNumberController::class, 'assign'])->name('sip-numbers.assign');
    Route::post('/sip-numbers/{sip_number}/release', [SipNumberController::class, 'release'])->name('sip-numbers.release');

    Route::resource('sip-extensions', SipExtensionController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-extensions' => 'sip_extension']);
    Route::resource('inbound-routes', InboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['inbound-routes' => 'inbound_route']);
    Route::resource('outbound-routes', OutboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['outbound-routes' => 'outbound_route']);
});

Route::middleware(['auth', 'admin:admin'])->group(function () {
    Route::get('/admin', [DashboardController::class, 'admin'])->name('admin');

    Route::resource('sip-gateways', SipGatewayController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-gateways' => 'sip_gateway']);

    Route::get('/admin/sip-numbers', [AdminSipNumberController::class, 'index'])->name('admin.sip-numbers.index');
    Route::post('/admin/sip-numbers', [AdminSipNumberController::class, 'store'])->name('admin.sip-numbers.store');
    Route::put('/admin/sip-numbers/{sip_number}', [AdminSipNumberController::class, 'update'])->name('admin.sip-numbers.update');
    Route::delete('/admin/sip-numbers/{sip_number}', [AdminSipNumberController::class, 'destroy'])->name('admin.sip-numbers.destroy');
    Route::post('/admin/sip-numbers/{sip_number}/approve', [AdminSipNumberController::class, 'approve'])->name('admin.sip-numbers.approve');
    Route::post('/admin/sip-numbers/{sip_number}/reject', [AdminSipNumberController::class, 'reject'])->name('admin.sip-numbers.reject');

    Route::get('/admin/tenants', [AdminTenantController::class, 'index'])->name('admin.tenants.index');
    Route::get('/admin/tenants/{tenant}', [AdminTenantController::class, 'show'])->name('admin.tenants.show');
    Route::put('/admin/tenants/{tenant}', [AdminTenantController::class, 'update'])->name('admin.tenants.update');
});

Route::post('/internal/freeswitch/xml', XmlController::class)
    ->middleware([AuthenticateFreeSwitch::class, 'throttle:freeswitch-xml'])
    ->name('freeswitch.xml');
