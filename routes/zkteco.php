<?php

use DewanLab\LaravelZktecoAdmsCore\Http\Controllers\AdmsController;
use DewanLab\LaravelZktecoAdmsCore\Http\Middleware\ValidateZkDevice;
use Illuminate\Support\Facades\Route;

$prefix = config('zkteco-adms-core.routes.prefix', 'iclock');
$middleware = config('zkteco-adms-core.routes.middleware', []);
$domain = config('zkteco-adms-core.routes.domain');

Route::group([
    'prefix' => $prefix,
    'middleware' => array_merge($middleware, [ValidateZkDevice::class]),
    'domain' => $domain,
], function () {
    Route::match(['GET', 'POST'], '/cdata', [AdmsController::class, 'handleCdata'])->name('zkteco.cdata');
    Route::match(['GET', 'POST'], '/querydata', [AdmsController::class, 'handleQueryData'])->name('zkteco.querydata');
    Route::get('/getrequest', [AdmsController::class, 'handleGetRequest'])->name('zkteco.getrequest');
    Route::post('/devicecmd', [AdmsController::class, 'handleDeviceCmd'])->name('zkteco.devicecmd');
    Route::match(['GET', 'POST'], '/registry', [AdmsController::class, 'handleRegistry'])->name('zkteco.registry');
    Route::get('/rtdata', [AdmsController::class, 'handleRtData'])->name('zkteco.rtdata');
});
