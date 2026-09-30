<?php

use App\Http\Controllers\Admin\AdminDidController;
use App\Http\Controllers\Admin\AdminNumberSetupController;
use App\Http\Controllers\Admin\CustomerConnectionReviewController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallHistoryController;
use App\Http\Controllers\CallQueueController;
use App\Http\Controllers\Customer\LineSetupWizardController;
use App\Http\Controllers\Customer\SetupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FreeSwitch\XmlController;
use App\Http\Controllers\InboundRouteController;
use App\Http\Controllers\IvrMenuController;
use App\Http\Controllers\LiveOverviewController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OutboundRouteController;
use App\Http\Controllers\QueueAvailabilityController;
use App\Http\Controllers\SipExtensionController;
use App\Http\Controllers\SipGatewayController;
use App\Http\Middleware\AuthenticateFreeSwitch;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/login', fn () => auth()->check()
    ? redirect(auth()->user()->homePath())
    : view('auth'))->name('login');

Route::post('/auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
Route::post('/auth/otp/verify', [AuthController::class, 'verify'])->middleware('throttle:otp-verify');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth');
Route::middleware('auth')->prefix('notifications')->name('notifications.')->group(function () {
    Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
    Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
});

Route::get('/', fn () => auth()->check()
    ? redirect(auth()->user()->homePath())
    : view('welcome'))->name('home');
Route::view('/plans', 'marketing.plans')->name('plans');
Route::view('/contact', 'marketing.contact')->name('contact');

Route::get('/access-denied', fn () => view('access-denied'))->middleware('auth')->name('access-denied');
Route::get('/dashboard', function (Request $request, DashboardController $controller) {
    return $request->user()->isAdmin() ? $controller->admin() : $controller->customer($request);
})->middleware(['auth', 'permission:'.Permissions::DASHBOARD_VIEW])->name('dashboard');
Route::get('/calls', [CallHistoryController::class, 'index'])
    ->middleware(['auth', 'permission:'.Permissions::CALLS_VIEW])->name('calls.index');
Route::middleware(['auth', 'permission:'.Permissions::LIVE_VIEW])->group(function () {
    Route::get('/live', [LiveOverviewController::class, 'index'])->name('live.index');
    Route::get('/live/state', [LiveOverviewController::class, 'state'])->name('live.state');
});
Route::get('/availability', [QueueAvailabilityController::class, 'index'])->middleware(['auth', 'permission:'.Permissions::QUEUES_WORK])->name('availability.index');
Route::post('/availability', [QueueAvailabilityController::class, 'update'])->middleware(['auth', 'permission:'.Permissions::QUEUES_WORK])->name('availability.update');

Route::middleware(['auth', 'permission:'.Permissions::PHONES_MANAGE])->prefix('menus')->name('ivr-menus.')->group(function () {
    Route::get('/', [IvrMenuController::class, 'index'])->name('index');
    Route::post('/', [IvrMenuController::class, 'store'])->name('store');
    Route::get('/{menu}/edit', [IvrMenuController::class, 'edit'])->name('edit');
    Route::put('/{menu}', [IvrMenuController::class, 'update'])->name('update');
    Route::post('/{menu}/publish', [IvrMenuController::class, 'publish'])->name('publish');
    Route::post('/{menu}/restore', [IvrMenuController::class, 'restore'])->name('restore');
    Route::delete('/{menu}', [IvrMenuController::class, 'destroy'])->name('destroy');
    Route::get('/{menu}/audio/{version}', [IvrMenuController::class, 'audio'])->name('audio');
});

Route::resource('teams', CallQueueController::class)
    ->middleware(['auth', 'permission:'.Permissions::PHONES_MANAGE])
    ->only(['index', 'store', 'update', 'destroy'])->parameters(['teams' => 'queue']);

Route::middleware(['auth', 'admin:admin'])->group(function () {
    Route::get('/admin', fn () => redirect()->route('dashboard'))->name('admin');
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
    Route::resource('sip-extensions', SipExtensionController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-extensions' => 'sip_extension']);
    Route::resource('sip-gateways', SipGatewayController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['sip-gateways' => 'sip_gateway']);
    Route::resource('inbound-routes', InboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['inbound-routes' => 'inbound_route']);
    Route::resource('outbound-routes', OutboundRouteController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['outbound-routes' => 'outbound_route']);

    Route::get('/admin/sip-numbers', [AdminDidController::class, 'index'])->name('admin.sip-numbers.index');
    Route::post('/admin/sip-numbers', [AdminDidController::class, 'store'])->name('admin.sip-numbers.store');
    Route::get('/admin/sip-numbers/{sip_number}/setup', [AdminNumberSetupController::class, 'show'])->name('admin.sip-numbers.setup');
    Route::put('/admin/sip-numbers/{sip_number}/gateway', [AdminNumberSetupController::class, 'updateGateway'])->name('admin.sip-numbers.gateway');
    Route::put('/admin/sip-numbers/{sip_number}', [AdminDidController::class, 'update'])->name('admin.sip-numbers.update');
    Route::delete('/admin/sip-numbers/{sip_number}', [AdminDidController::class, 'destroy'])->name('admin.sip-numbers.destroy');
    Route::get('/admin/customer-connections', [CustomerConnectionReviewController::class, 'index'])->name('admin.customer-connections.index');
    Route::post('/admin/customer-connections/gateways/{gateway}/approve', [CustomerConnectionReviewController::class, 'approveGateway'])->name('admin.customer-connections.gateways.approve');
    Route::post('/admin/customer-connections/gateways/{gateway}/reject', [CustomerConnectionReviewController::class, 'rejectGateway'])->name('admin.customer-connections.gateways.reject');
    Route::post('/admin/customer-connections/numbers/{number}/approve', [CustomerConnectionReviewController::class, 'approveNumber'])->name('admin.customer-connections.numbers.approve');
    Route::post('/admin/customer-connections/numbers/{number}/reject', [CustomerConnectionReviewController::class, 'rejectNumber'])->name('admin.customer-connections.numbers.reject');
});

Route::middleware('auth')->prefix('setup')->name('customer.setup.')->group(function () {
    Route::get('/wizard', [LineSetupWizardController::class, 'show'])->name('wizard');
    Route::post('/wizard/answer', [LineSetupWizardController::class, 'chooseAnswer'])->name('wizard.answer');
    Route::post('/wizard/gateway', [LineSetupWizardController::class, 'chooseGateway'])->name('wizard.gateway');
    Route::post('/wizard/number', [LineSetupWizardController::class, 'chooseNumber'])->name('wizard.number');
    Route::post('/wizard/phone', [LineSetupWizardController::class, 'createPhone'])->name('wizard.phone');
    Route::post('/wizard/outbound/{extension}', [LineSetupWizardController::class, 'setOutbound'])->name('wizard.outbound');
    Route::middleware('permission:'.Permissions::PROVIDERS_MANAGE)->group(function () {
        Route::get('/provider', [SetupController::class, 'provider'])->name('provider');
        Route::post('/providers', [SetupController::class, 'storeProvider'])->name('providers.store');
        Route::put('/providers/{gateway}', [SetupController::class, 'updateProvider'])->name('providers.update');
    });
    Route::middleware('permission:'.Permissions::NUMBERS_MANAGE)->group(function () {
        Route::get('/number', [SetupController::class, 'number'])->name('number');
        Route::post('/numbers', [SetupController::class, 'storeNumber'])->name('numbers.store');
        Route::get('/numbers/{number}/edit', [SetupController::class, 'editNumber'])->name('numbers.edit');
        Route::put('/numbers/{number}', [SetupController::class, 'updateNumber'])->name('numbers.update');
    });
    Route::middleware('permission:'.Permissions::PHONES_MANAGE)->group(function () {
        Route::get('/answer/{number}', [SetupController::class, 'answer'])->name('answer');
        Route::post('/answer/{number}', [SetupController::class, 'storeAnswer'])->name('answer.store');
        Route::get('/answer/{number}/announcement', [SetupController::class, 'announcement'])->name('answer.announcement');
        Route::get('/phone/{extension}', [SetupController::class, 'phone'])->name('phone');
        Route::post('/phone/{extension}/reset', [SetupController::class, 'resetPhonePassword'])->name('phone.reset');
    });
    Route::get('/lines', [SetupController::class, 'lines'])->middleware('permission:'.Permissions::LINES_VIEW)->name('lines');
});

Route::post('/internal/freeswitch/xml', XmlController::class)
    ->middleware([AuthenticateFreeSwitch::class, 'throttle:freeswitch-xml'])
    ->name('freeswitch.xml');
